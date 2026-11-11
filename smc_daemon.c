/*
 * smc_daemon.c — Root LaunchDaemon that reads SMC temperatures on Apple Silicon.
 * Uses the same approach as Stats.app: IOConnectCallStructMethod with SMCKeyData_t.
 * Listens on Unix socket at /tmp/smc_helper.sock for temp requests.
 */

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <sys/socket.h>
#include <sys/un.h>
#include <sys/stat.h>
#include <signal.h>
#include <stdint.h>
#include <IOKit/IOKitLib.h>

#define SOCKET_PATH "/tmp/smc_helper.sock"

/* SMC method indices (matching Stats' SMCKeys enum) */
#define SMC_KERNEL_INDEX    2
#define SMC_READ_KEY_INFO   9
#define SMC_READ_BYTES      5
#define SMC_GET_KEYS_COUNT  4
#define SMC_GET_KEY_FROM_INDEX 8

/* SMC data structure — must match Stats' SMCKeyData_t exactly */
typedef struct {
    UInt8 bytes[32];
} SMCBytes_t;

typedef struct {
    UInt8  major;
    UInt8  minor;
    UInt8  build;
    UInt8  reserved;
    UInt16 release;
} vers_t;

typedef struct {
    UInt16 version;
    UInt16 length;
    UInt32 cpuPLimit;
    UInt32 gpuPLimit;
    UInt32 memPLimit;
} LimitData_t;

typedef struct {
    IOByteCount32 dataSize;
    UInt32 dataType;
    UInt8 dataAttributes;
} keyInfo_t;

typedef struct {
    UInt32 key;
    vers_t vers;
    LimitData_t pLimitData;
    keyInfo_t keyInfo;
    UInt16 padding;
    UInt8 result;
    UInt8 status;
    UInt8 data8;
    UInt32 data32;
    SMCBytes_t bytes;
} __attribute__((packed)) SMCKeyData_t;

static io_connect_t smc_conn;
static int server_fd;
static volatile int running = 1;

#ifndef kIOMainPortDefault
#define kIOMainPortDefault kIOMasterPortDefault
#endif

/* Open SMC connection — uses IOServiceGetMatchingServices (plural) like Stats does */
int open_smc(void) {
    kern_return_t result;
    io_iterator_t iterator = 0;
    io_object_t device;

    CFMutableDictionaryRef matching = IOServiceMatching("AppleSMC");
    if (!matching) return -1;

    result = IOServiceGetMatchingServices(kIOMainPortDefault, matching, &iterator);
    if (result != kIOReturnSuccess) {
        fprintf(stderr, "IOServiceGetMatchingServices failed: %d\n", result);
        return -1;
    }

    device = IOIteratorNext(iterator);
    IOObjectRelease(iterator);
    if (device == 0) {
        fprintf(stderr, "No AppleSMC device found\n");
        return -1;
    }

    result = IOServiceOpen(device, mach_task_self(), 0, &smc_conn);
    IOObjectRelease(device);
    if (result != kIOReturnSuccess) {
        fprintf(stderr, "IOServiceOpen failed: %d\n", result);
        return -1;
    }

    return 0;
}

void close_smc(void) {
    if (smc_conn) IOServiceClose(smc_conn);
}

/* Call SMC via struct method (like Stats' call() function) */
kern_return_t smc_call(UInt8 index, SMCKeyData_t *input, SMCKeyData_t *output) {
    size_t inputSize = sizeof(SMCKeyData_t);
    size_t outputSize = sizeof(SMCKeyData_t);
    return IOConnectCallStructMethod(smc_conn, index, input, inputSize, output, &outputSize);
}

/* Convert 4-char string to UInt32 */
UInt32 str_to_key(const char *s) {
    return ((UInt32)s[0] << 24) | ((UInt32)s[1] << 16) | ((UInt32)s[2] << 8) | (UInt32)s[3];
}

/* Convert UInt32 to 4-char string */
void key_to_str(UInt32 key, char *out) {
    out[0] = (key >> 24) & 0xFF;
    out[1] = (key >> 16) & 0xFF;
    out[2] = (key >> 8) & 0xFF;
    out[3] = key & 0xFF;
    out[4] = 0;
}

/* Read a single SMC key value */
int read_key(const char *keyStr, double *outValue) {
    SMCKeyData_t input;
    SMCKeyData_t output;
    memset(&input, 0, sizeof(input));
    memset(&output, 0, sizeof(output));

    input.key = str_to_key(keyStr);
    input.data8 = SMC_READ_KEY_INFO;

    kern_return_t result = smc_call(SMC_KERNEL_INDEX, &input, &output);
    if (result != kIOReturnSuccess) return -1;

    UInt32 dataSize = output.keyInfo.dataSize;
    UInt32 dataType = output.keyInfo.dataType;

    input.keyInfo.dataSize = dataSize;
    input.data8 = SMC_READ_BYTES;

    result = smc_call(SMC_KERNEL_INDEX, &input, &output);
    if (result != kIOReturnSuccess) return -1;

    if (dataSize == 0) return -1;

    /* Parse based on dataType (matching Stats' getValue logic) */
    char typeStr[5];
    key_to_str(dataType, typeStr);

    if (strcmp(typeStr, "sp78") == 0 && dataSize >= 2) {
        int16_t val = (output.bytes.bytes[0] << 8) | output.bytes.bytes[1];
        *outValue = (double)val / 256.0;
        return 0;
    }
    if (strcmp(typeStr, "flt ") == 0 && dataSize >= 4) {
        float f;
        memcpy(&f, output.bytes.bytes, 4);
        *outValue = (double)f;
        return 0;
    }
    if (strcmp(typeStr, "ui8 ") == 0 && dataSize >= 1) {
        *outValue = (double)output.bytes.bytes[0];
        return 0;
    }
    if (strcmp(typeStr, "ui16") == 0 && dataSize >= 2) {
        uint16_t val = (output.bytes.bytes[0] << 8) | output.bytes.bytes[1];
        *outValue = (double)val;
        return 0;
    }
    if (strcmp(typeStr, "ui32") == 0 && dataSize >= 4) {
        uint32_t val = (output.bytes.bytes[0] << 24) | (output.bytes.bytes[1] << 16) |
                       (output.bytes.bytes[2] << 8) | output.bytes.bytes[3];
        *outValue = (double)val;
        return 0;
    }
    if (strcmp(typeStr, "sp5a") == 0 && dataSize >= 2) {
        uint16_t val = (output.bytes.bytes[0] << 8) | output.bytes.bytes[1];
        *outValue = (double)val / 1024.0;
        return 0;
    }
    if (strcmp(typeStr, "sp69") == 0 && dataSize >= 2) {
        uint16_t val = (output.bytes.bytes[0] << 8) | output.bytes.bytes[1];
        *outValue = (double)val / 512.0;
        return 0;
    }
    if (strcmp(typeStr, "sp96") == 0 && dataSize >= 2) {
        int16_t val = (output.bytes.bytes[0] << 8) | output.bytes.bytes[1];
        *outValue = (double)val / 64.0;
        return 0;
    }
    if (strcmp(typeStr, "sp87") == 0 && dataSize >= 2) {
        int16_t val = (output.bytes.bytes[0] << 8) | output.bytes.bytes[1];
        *outValue = (double)val / 128.0;
        return 0;
    }
    if (strcmp(typeStr, "spb4") == 0 && dataSize >= 2) {
        int16_t val = (output.bytes.bytes[0] << 8) | output.bytes.bytes[1];
        *outValue = (double)val / 16.0;
        return 0;
    }
    if (strcmp(typeStr, "sp4b") == 0 && dataSize >= 2) {
        uint16_t val = (output.bytes.bytes[0] << 8) | output.bytes.bytes[1];
        *outValue = (double)val / 2048.0;
        return 0;
    }
    if (strcmp(typeStr, "sp3c") == 0 && dataSize >= 2) {
        uint16_t val = (output.bytes.bytes[0] << 8) | output.bytes.bytes[1];
        *outValue = (double)val / 4096.0;
        return 0;
    }
    if (strcmp(typeStr, "sp1e") == 0 && dataSize >= 2) {
        uint16_t val = (output.bytes.bytes[0] << 8) | output.bytes.bytes[1];
        *outValue = (double)val / 16384.0;
        return 0;
    }
    if (strcmp(typeStr, "fpe2") == 0 && dataSize >= 2) {
        int val = (output.bytes.bytes[0] << 6) + (output.bytes.bytes[1] >> 2);
        *outValue = (double)val;
        return 0;
    }

    return -1; /* unknown data type */
}

/* Get total SMC key count */
uint32_t get_key_count(void) {
    SMCKeyData_t input;
    SMCKeyData_t output;
    memset(&input, 0, sizeof(input));
    memset(&output, 0, sizeof(output));

    input.data8 = SMC_GET_KEYS_COUNT;
    kern_return_t result = smc_call(SMC_KERNEL_INDEX, &input, &output);
    if (result != kIOReturnSuccess) return 0;
    return (uint32_t)output.data32;
}

/* Get key by index */
int get_key_by_index(uint32_t index, char *outKey) {
    SMCKeyData_t input;
    SMCKeyData_t output;
    memset(&input, 0, sizeof(input));
    memset(&output, 0, sizeof(output));

    input.data8 = SMC_GET_KEY_FROM_INDEX;
    input.data32 = index;

    kern_return_t result = smc_call(SMC_KERNEL_INDEX, &input, &output);
    if (result != kIOReturnSuccess) return -1;

    key_to_str(output.key, outKey);
    return 0;
}

/* Check if key string is printable */
int is_printable(const char *s) {
    for (int i = 0; i < 4; i++)
        if (s[i] < 0x20 || s[i] > 0x7E) return 0;
    return 1;
}

/* Categorize a temp key */
const char *key_category(const char *keyStr) {
    if (keyStr[0] != 'T') return NULL;
    if (keyStr[1] == 'C') return "cpu";
    if (keyStr[1] == 'G') return "gpu";
    if (keyStr[1] == 's' || keyStr[1] == 'S') return "sensor";
    if (keyStr[1] == 'p') return "platform";
    if (keyStr[1] == 'h') return "heatsink";
    if (keyStr[1] == 'a') return "ambient";
    if (keyStr[1] == 'W') return "wireless";
    if (keyStr[1] == 'm') return "memory";
    if (keyStr[1] == 'b') return "battery";
    return "other";
}

/* Read all temps and write to fd */
void read_all_temps(int fd) {
    uint32_t count = get_key_count();
    if (count == 0) {
        dprintf(fd, "ERROR no_smc_keys count=0\n");
        return;
    }

    char line[256];
    double cpu_temp = -1.0, gpu_temp = -1.0;

    for (uint32_t i = 0; i < count; i++) {
        char keyStr[5];
        if (get_key_by_index(i, keyStr) != 0) continue;
        if (!is_printable(keyStr)) continue;

        const char *cat = key_category(keyStr);
        if (!cat) continue;

        double val = 0.0;
        if (read_key(keyStr, &val) != 0) continue;
        if (val < 0 || val > 200) continue;

        snprintf(line, sizeof(line), "%s %s %.1f\n", keyStr, cat, val);
        dprintf(fd, "%s", line);

        if (strcmp(cat, "cpu") == 0 && val > cpu_temp) cpu_temp = val;
        if (strcmp(cat, "gpu") == 0 && val > gpu_temp) gpu_temp = val;
    }

    dprintf(fd, "---\n");
    if (cpu_temp > 0) dprintf(fd, "cpu_temp %.1f\n", cpu_temp);
    if (gpu_temp > 0) dprintf(fd, "gpu_temp %.1f\n", gpu_temp);
    if (cpu_temp < 0 && gpu_temp < 0) dprintf(fd, "cpu_temp 0.0\ngpu_temp 0.0\n");
}

void handle_signal(int sig) {
    running = 0;
}

int main(int argc, char *argv[]) {
    /* One-shot mode for testing */
    if (argc > 1 && strcmp(argv[1], "--oneshot") == 0) {
        if (open_smc() != 0) {
            fprintf(stderr, "Cannot open SMC\n");
            return 1;
        }
        read_all_temps(STDOUT_FILENO);
        close_smc();
        return 0;
    }

    /* Daemon mode: listen on Unix socket */
    signal(SIGTERM, handle_signal);
    signal(SIGINT, handle_signal);

    if (open_smc() != 0) {
        fprintf(stderr, "Cannot open SMC connection\n");
        return 1;
    }

    unlink(SOCKET_PATH);

    server_fd = socket(AF_UNIX, SOCK_STREAM, 0);
    if (server_fd < 0) {
        perror("socket");
        return 1;
    }

    struct sockaddr_un addr;
    memset(&addr, 0, sizeof(addr));
    addr.sun_family = AF_UNIX;
    strncpy(addr.sun_path, SOCKET_PATH, sizeof(addr.sun_path) - 1);

    if (bind(server_fd, (struct sockaddr *)&addr, sizeof(addr)) < 0) {
        perror("bind");
        return 1;
    }

    chmod(SOCKET_PATH, 0666);

    if (listen(server_fd, 5) < 0) {
        perror("listen");
        return 1;
    }

    fprintf(stderr, "SMC daemon listening on %s\n", SOCKET_PATH);

    while (running) {
        int client_fd = accept(server_fd, NULL, NULL);
        if (client_fd < 0) {
            if (running) perror("accept");
            break;
        }
        read_all_temps(client_fd);
        close(client_fd);
    }

    close(server_fd);
    unlink(SOCKET_PATH);
    close_smc();
    return 0;
}