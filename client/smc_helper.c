/*
 * smc_helper.c — Privileged SMC temperature reader for mac-monitor.
 * Reads CPU/GPU temps from Apple SMC via IOKit.
 * Must run as root to get the privileged SMC connection.
 * Output: lines of "KEY NAME VALUE" for each temp sensor found,
 * then "---" and summary lines "cpu_temp X.X" / "gpu_temp X.X".
 */

#include <stdio.h>
#include <stdint.h>
#include <string.h>
#include <IOKit/IOKitLib.h>

#define kSMCGetKeyInfo    9
#define kSMCReadKey       5
#define kSMCGetKeysCount  4
#define kSMCGetKeyFromIndex 8

typedef struct {
    uint32_t key;
    uint32_t dataSize;
    uint32_t dataType;
    uint8_t  dataAttributes;
} __attribute__((packed)) SMCKeyInfo;

static io_connect_t conn;

/* Use kIOMainPortDefault on macOS 12+, fall back to kIOMasterPortDefault */
#ifndef kIOMainPortDefault
#define kIOMainPortDefault kIOMasterPortDefault
#endif

int open_smc(void) {
    CFMutableDictionaryRef match = IOServiceMatching("AppleSMC");
    if (!match) return -1;

    io_service_t service = IOServiceGetMatchingService(kIOMainPortDefault, match);
    if (!service) return -1;

    kern_return_t r = IOServiceOpen(service, mach_task_self(), 0, &conn);
    IOObjectRelease(service);
    return (int)r;
}

void close_smc(void) {
    if (conn) IOServiceClose(conn);
}

uint32_t get_key_count(void) {
    uint64_t input = 0;
    uint32_t count = 0;
    size_t outSize = sizeof(count);
    kern_return_t r = IOConnectCallMethod(conn, kSMCGetKeysCount,
        &input, 1, NULL, 0, NULL, NULL, &count, &outSize);
    if (r != kIOReturnSuccess) return 0;
    return count;
}

int get_key_by_index(uint32_t index, uint32_t *outKey) {
    uint64_t idx = index;
    size_t outSize = sizeof(*outKey);
    kern_return_t r = IOConnectCallMethod(conn, kSMCGetKeyFromIndex,
        &idx, 1, NULL, 0, NULL, NULL, outKey, &outSize);
    return (r == kIOReturnSuccess) ? 0 : -1;
}

int read_key(uint32_t key, SMCKeyInfo *info, uint8_t *buf) {
    uint64_t keyInput = key;
    size_t infoOutSize = sizeof(*info);
    kern_return_t r = IOConnectCallMethod(conn, kSMCGetKeyInfo,
        &keyInput, 1, NULL, 0, NULL, NULL, info, &infoOutSize);
    if (r != kIOReturnSuccess) return -1;

    size_t dataSize = info->dataSize;
    if (dataSize > 32) return -1;

    r = IOConnectCallMethod(conn, kSMCReadKey,
        &keyInput, 1, NULL, 0, NULL, NULL, buf, &dataSize);
    return (r == kIOReturnSuccess) ? 0 : -1;
}

float parse_temp(SMCKeyInfo *info, uint8_t *buf) {
    /* sp78: signed 16-bit fixed point 8.8 */
    if (info->dataType == 0x73703738 && info->dataSize >= 2) {
        int16_t val = (buf[0] << 8) | buf[1];
        return val / 256.0f;
    }
    /* flt: 32-bit float */
    if (info->dataType == 0x666c7420 && info->dataSize >= 4) {
        float f;
        memcpy(&f, buf, 4);
        return f;
    }
    /* ui8: unsigned 8-bit integer */
    if (info->dataType == 0x75693820 && info->dataSize >= 1) {
        return (float)buf[0];
    }
    /* ui16: unsigned 16-bit integer */
    if (info->dataType == 0x75693136 && info->dataSize >= 2) {
        uint16_t val = (buf[0] << 8) | buf[1];
        return (float)val;
    }
    /* sp3e: signed 16-bit, 2.14 fixed point */
    if (info->dataType == 0x73703365 && info->dataSize >= 2) {
        int16_t val = (buf[0] << 8) | buf[1];
        return val / 16384.0f;
    }
    /* sp4e: signed 16-bit, 4.12 fixed point */
    if (info->dataType == 0x73703465 && info->dataSize >= 2) {
        int16_t val = (buf[0] << 8) | buf[1];
        return val / 4096.0f;
    }
    /* sp5a: signed 16-bit, 10.6 fixed point */
    if (info->dataType == 0x73703561 && info->dataSize >= 2) {
        int16_t val = (buf[0] << 8) | buf[1];
        return val / 64.0f;
    }
    /* sp6a: signed 16-bit, 6.10 fixed point */
    if (info->dataType == 0x73703661 && info->dataSize >= 2) {
        int16_t val = (buf[0] << 8) | buf[1];
        return val / 1024.0f;
    }
    return -999.0f;
}

int is_printable_key(const char *keyStr) {
    for (int j = 0; j < 4; j++) {
        if (keyStr[j] < 0x20 || keyStr[j] > 0x7E) return 0;
    }
    return 1;
}

const char *key_category(const char *keyStr) {
    /* CPU temperature: TC0x, TC1x, etc. */
    if (keyStr[0] == 'T' && keyStr[1] == 'C') return "cpu";
    /* GPU temperature: TG0x, TG1x, etc. */
    if (keyStr[0] == 'T' && keyStr[1] == 'G') return "gpu";
    /* Other temp sensors */
    if (keyStr[0] == 'T' && (keyStr[1] == 's' || keyStr[1] == 'S')) return "sensor";
    if (keyStr[0] == 'T' && keyStr[1] == 'p') return "platform";
    if (keyStr[0] == 'T' && keyStr[1] == 'h') return "heatsink";
    if (keyStr[0] == 'T' && keyStr[1] == 'a') return "ambient";
    if (keyStr[0] == 'T' && keyStr[1] == 'W') return "wireless";
    if (keyStr[0] == 'T' && keyStr[1] == 'm') return "memory";
    if (keyStr[0] == 'T' && keyStr[1] == 'b') return "battery";
    if (keyStr[0] == 'T') return "other";
    return NULL;
}

int main(void) {
    if (open_smc() != 0) {
        fprintf(stderr, "Cannot open SMC connection\n");
        return 1;
    }

    uint32_t count = get_key_count();
    if (count == 0) {
        fprintf(stderr, "No SMC keys found (are you running as root?)\n");
        close_smc();
        return 1;
    }

    float cpu_temp = -1.0f, gpu_temp = -1.0f;

    for (uint32_t i = 0; i < count; i++) {
        uint32_t key = 0;
        if (get_key_by_index(i, &key) != 0) continue;

        char keyStr[5];
        keyStr[0] = (key >> 24) & 0xFF;
        keyStr[1] = (key >> 16) & 0xFF;
        keyStr[2] = (key >> 8) & 0xFF;
        keyStr[3] = key & 0xFF;
        keyStr[4] = 0;

        if (!is_printable_key(keyStr)) continue;

        const char *cat = key_category(keyStr);
        if (!cat) continue;  /* Not a temperature key */

        SMCKeyInfo info;
        memset(&info, 0, sizeof(info));
        uint8_t buf[32] = {0};

        if (read_key(key, &info, buf) != 0) continue;

        float temp = parse_temp(&info, buf);
        if (temp < 0 || temp > 200) continue;

        /* Print every temp sensor found */
        printf("%s %s %.1f\n", keyStr, cat, temp);

        /* Track best CPU and GPU temps (highest = closest to die) */
        if (strcmp(cat, "cpu") == 0 && temp > cpu_temp) cpu_temp = temp;
        if (strcmp(cat, "gpu") == 0 && temp > gpu_temp) gpu_temp = temp;
    }

    /* Summary for easy parsing by monitor.py */
    printf("---\n");
    if (cpu_temp > 0) printf("cpu_temp %.1f\n", cpu_temp);
    if (gpu_temp > 0) printf("gpu_temp %.1f\n", gpu_temp);

    close_smc();
    return 0;
}