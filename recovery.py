#!/usr/bin/env python3
"""Local, single-owner Halogen supervisor. Python 3.9+, no dependencies.
Only fixed local profiles are executable; the relay never supplies shell commands.
"""
import argparse
import copy
import fcntl
import json
import math
import os
from pathlib import Path
import signal
import subprocess
import threading
import time
import urllib.request

VERSION = "1.2.0-rc1"
NAME = "halogen"
LABEL = "io.mac-monitor.recovery=1"


def atomic_json(path, data):
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    temp = path.with_suffix('.tmp')
    with open(temp, 'w') as f:
        os.chmod(temp, 0o600)
        json.dump(data, f)
        f.flush()
        os.fsync(f.fileno())
    os.replace(temp, path)


def memory():
    values = {}
    for line in Path('/proc/meminfo').read_text().splitlines():
        key, value = line.split(':', 1)
        values[key] = int(value.split()[0]) * 1024
    return {'total_gib': values['MemTotal'] / 2**30,
            'available_gib': values['MemAvailable'] / 2**30,
            'swap_used_gib': (values['SwapTotal'] - values['SwapFree']) / 2**30}


def target_memory(profile, total):
    explicit = profile.get('min_available_gib')
    target = float(explicit) if explicit is not None else total - float(profile.get('system_reserve_gib', 8))
    if not math.isfinite(target) or not 1 <= target <= total:
        raise ValueError('RAM-Ziel ungueltig oder groesser als sichtbarer Systemspeicher')
    return target


def proc_info(pid):
    try:
        p = Path('/proc') / str(pid)
        stat = (p/'stat').read_text().rsplit(')', 1)[1].split()
        cmd = (p/'cmdline').read_bytes().replace(b'\0', b' ').decode(errors='replace')
        return {'pid': int(pid), 'uid': p.stat().st_uid, 'ppid': int(stat[1]),
                'start': stat[19], 'exe': Path(os.readlink(p/'exe')).name.replace(' (deleted)', ''), 'cmd': cmd}
    except (OSError, ValueError, IndexError):
        return None


def matches_app(p, names):
    if p['exe'] in names or p['exe'].startswith('LM-Studio-'):
        return True
    if p['exe'] == 'electron' and any(x in p['cmd'] for x in ('/lm-studio/', '/LM Studio/')):
        return True
    # Java is eligible ONLY when its command line explicitly identifies Android Studio.
    return p['exe'] == 'java' and any(x in p['cmd'] for x in ('/android-studio/', 'idea.platform.prefix=AndroidStudio'))


def process_targets(names):
    allp = [proc_info(p.name) for p in Path('/proc').iterdir() if p.name.isdigit()]
    allp = [p for p in allp if p and p['uid'] == os.getuid()]
    protected = {os.getpid()}
    parent = os.getppid()
    while parent > 1:
        protected.add(parent)
        info = proc_info(parent)
        if not info: break
        parent = info['ppid']
    selected = {p['pid'] for p in allp if p['pid'] not in protected and matches_app(p, names)}
    for _ in range(32):
        children = {p['pid'] for p in allp if p['ppid'] in selected and p['pid'] not in protected
                    and p['exe'] not in ('sshd','systemd','recovery.py')}
        if children <= selected: break
        selected |= children
    return [p for p in allp if p['pid'] in selected]


def signal_same(p, sig):
    now = proc_info(p['pid'])
    if now and now['uid'] == os.getuid() and now['start'] == p['start']:
        try:
            os.kill(p['pid'], sig)
            return True
        except ProcessLookupError:
            pass
    return False


class Supervisor:
    def __init__(self, cfg):
        self.cfg = cfg
        self.directory = Path(cfg['state_dir']).expanduser()
        self.directory.mkdir(parents=True, exist_ok=True, mode=0o700)
        self.lock = threading.RLock()
        self.cancel = threading.Event()
        self.worker = None
        self.worker_revision = None
        self.failures = 0
        self.attempts = []
        self.seen_revision = -1
        self.desired = 'off'
        self.active = None
        self.next_retry = 0
        self.last_health = 0
        self.status = {'version':VERSION,'phase':'waiting','message':'Warte auf Steuerverbindung',
                       'events':[], 'desired':'off','active':None,'revision':-1}
        saved = self.directory/'state.json'
        if saved.exists():
            data = json.loads(saved.read_text())
            self.desired = data.get('desired','off')
            self.seen_revision = int(data.get('revision',-1))
            self.attempts = data.get('attempts',[])
            self.active = data.get('active')
        self.status.update(desired=self.desired, revision=self.seen_revision, active=self.active)

    def persist(self):
        with self.lock:
            atomic_json(self.directory/'state.json', {'desired':self.desired,'revision':self.seen_revision,
                        'attempts':self.attempts,'active':self.active})

    def event(self, phase, message):
        with self.lock:
            self.status.update(phase=phase, message=str(message)[:1200])
            self.status['events'] = (self.status['events'] + [{'ts':int(time.time()),'phase':phase,'message':str(message)[:1200]}])[-60:]
        print(phase, message, flush=True)

    def notify(self, text):
        try:
            c = json.loads(Path(self.cfg['telegram_config']).expanduser().read_text())
            body = json.dumps({'chat_id':c['chat_id'],'text':'Evo-X3 · '+text}).encode()
            req = urllib.request.Request('https://api.telegram.org/bot'+c['bot_token']+'/sendMessage',data=body,
                                         headers={'Content-Type':'application/json'})
            with urllib.request.urlopen(req, timeout=8) as r: r.read(10000)
        except Exception as e:
            print('Telegram nicht gesendet:',type(e).__name__,flush=True)

    def command(self, args, timeout=60, check=True):
        r = subprocess.run(args, capture_output=True, text=True, timeout=timeout)
        if check and r.returncode:
            raise RuntimeError(' '.join(args[:3])+': '+(r.stderr or r.stdout)[-1800:])
        return r

    def relay(self, body=None):
        headers = {'Authorization':'Bearer '+self.cfg['token'],'Content-Type':'application/json'}
        url = self.cfg['relay_url']+'?machine='+self.cfg['machine_id']
        req = urllib.request.Request(url, data=None if body is None else json.dumps(body).encode(), headers=headers)
        with urllib.request.urlopen(req,timeout=12) as r:
            return json.loads(r.read(262144))

    def inspect(self):
        r = self.command(['podman','inspect',NAME],check=False,timeout=10)
        if r.returncode: return None
        return json.loads(r.stdout)[0]

    def owned(self, info):
        return (info.get('Config',{}).get('Labels') or {}).get('io.mac-monitor.recovery') == '1'

    def stop_container(self):
        info = self.inspect()
        if info is None: return
        if not self.owned(info):
            raise RuntimeError('Container halogen ist nicht verwaltet. Einmalige Uebernahme gemaess INSTALL.md erforderlich.')
        self.command(['podman','stop','--time','30',NAME],timeout=45)
        self.command(['podman','rm',NAME],timeout=20)

    def api_models(self):
        headers = {}
        if self.cfg.get('api_key'): headers['Authorization']='Bearer '+self.cfg['api_key']
        req=urllib.request.Request(self.cfg['health_url'],headers=headers)
        with urllib.request.urlopen(req,timeout=5) as r:
            data=json.loads(r.read(262144))
        models=data.get('data',[])
        return models[0]['id'] if models else None

    def completion_probe(self, model):
        headers={'Content-Type':'application/json'}
        if self.cfg.get('api_key'): headers['Authorization']='Bearer '+self.cfg['api_key']
        body={'model':model,'messages':[{'role':'user','content':'Reply OK.'}], 'max_tokens':1,'stream':False}
        req=urllib.request.Request(self.cfg['completion_url'],data=json.dumps(body).encode(),headers=headers)
        with urllib.request.urlopen(req,timeout=90) as r:
            result=json.loads(r.read(262144))
        if not result.get('choices'): raise RuntimeError('Generierungstest ohne choices')

    def wait(self, seconds):
        if self.cancel.wait(seconds): raise InterruptedError('Durch neuen Webauftrag abgebrochen')

    def cleanup(self, profile):
        m=memory(); target=target_memory(profile,m['total_gib'])
        with self.lock: self.status['target_available_gib']=target
        if m['available_gib'] >= target: return
        if not self.cfg.get('allow_emergency_cleanup'):
            raise RuntimeError('Zu wenig RAM; Notfallbereinigung nicht freigegeben')
        self.event('cleaning','RAM freigeben: nur konfigurierte Anwendungen und Dienste')
        for unit in self.cfg['user_services']:
            self.command(['systemctl','--user','stop',unit],check=False,timeout=15)
        if self.cfg.get('system_ollama_helper'):
            r=self.command(['sudo','-n','/usr/local/sbin/mm-stop-ollama'],check=False,timeout=45)
            if r.returncode: self.event('cleaning','System-Ollama konnte nicht gestoppt werden: '+r.stderr[-300:])
        for name in self.cfg['extra_container_names']:
            self.command(['podman','stop','--time','20',name],check=False,timeout=30)
        targets=process_targets(set(self.cfg['process_names']))
        for p in targets: signal_same(p,signal.SIGTERM)
        if targets:
            self.event('cleaning','SIGTERM an '+', '.join(sorted({p['exe'] for p in targets})))
            self.wait(float(self.cfg['force_after_seconds']))
            if memory()['available_gib'] >= target: return
            for p in targets: signal_same(p,signal.SIGKILL)
        deadline=time.monotonic()+float(self.cfg['memory_wait_seconds'])
        while time.monotonic()<deadline:
            self.wait(2)
            if memory()['available_gib'] >= target: return
        m=memory()
        raise RuntimeError('RAM-Ziel nicht erreicht: %.1f / %.1f GiB verfuegbar. Systemdienste, fremde Benutzer oder GPU-Belegung pruefen.' % (m['available_gib'],target))

    def start_args(self, key):
        p=self.cfg['profiles'][key]
        folder=Path(p['models_dir']).expanduser(); folder.mkdir(parents=True,exist_ok=True)
        args=['podman','run','-d','--pull=never','--name',NAME,'--label',LABEL,
              '--label','io.mac-monitor.profile='+key,'-p',self.cfg.get('bind_address','127.0.0.1')+':8731:8731',
              '--device','/dev/kfd','--device','/dev/dri','--group-add','keep-groups',
              '--ipc=host','--ulimit','memlock=-1:-1']
        args+=p.get('extra_args',[])
        env={'HALOGEN_DOWNLOAD':p['download'],**p.get('env',{})}
        for k,v in env.items(): args+=['-e',k+'='+v]
        args+=['-v',str(folder)+':/models',p['image']]
        return args

    def transition(self, key):
        try:
            if key != 'off':
                p=self.cfg['profiles'][key]
                self.event('preflight','Pruefe lokales Container-Image vor dem Stoppen')
                self.command(['podman','image','exists',p['image']],timeout=15)
                target_memory(p,memory()['total_gib'])
            self.event('stopping','Bisheriges Halogen kontrolliert stoppen')
            self.stop_container()
            with self.lock: self.active=None; self.status['active']=None
            self.persist()
            if key=='off':
                self.event('off','Bewusst ausgeschaltet; kein automatischer Neustart')
                return
            self.wait(1)
            import socket
            with socket.socket() as probe:
                if probe.connect_ex(('127.0.0.1',8731)) == 0:
                    raise RuntimeError('Port 8731 ist noch belegt. Alten unbenannten Container einmalig stoppen; keine fremden Prozesse werden beendet.')
            before=memory()['available_gib']
            self.cleanup(p)
            self.wait(1)
            after=memory()['available_gib']
            with self.lock: self.status['released_gib']=round(max(0,after-before),2)
            self.event('starting','Container neu erzeugen: '+p['label'])
            self.command(self.start_args(key),timeout=60)
            self.event('loading','Modell laedt; beim ersten Start kann ein Download erforderlich sein')
            deadline=time.monotonic()+float(self.cfg['startup_timeout_seconds'])
            while time.monotonic()<deadline:
                self.wait(5)
                info=self.inspect()
                if not info or not info.get('State',{}).get('Running'):
                    logs=self.command(['podman','logs','--tail','20',NAME],check=False,timeout=10)
                    raise RuntimeError('Container beendet: '+(logs.stdout+logs.stderr)[-1600:])
                try: model=self.api_models()
                except Exception: continue
                if not model: continue
                self.event('verifying','API erreichbar; pruefe echte Token-Generierung')
                self.completion_probe(model)
                self.wait(0)
                with self.lock: self.active=key; self.status['active']=key; self.status['model_id']=model
                self.persist()
                self.failures=0
                self.event('ready','Modell bereit; Generierungstest erfolgreich')
                self.notify(p['label']+' ist bereit. Generierungstest erfolgreich.')
                return
            raise RuntimeError('Ladezeitlimit erreicht; kein erfolgreicher Generierungstest')
        except InterruptedError:
            self.event('cancelled','Neuer Webauftrag wird uebernommen')
        except Exception as e:
            self.next_retry=time.time()+float(self.cfg['retry_delay_seconds'])
            self.event('error',str(e))
            self.notify('Wiederherstellung fehlgeschlagen: '+str(e)[:600])

    def launch(self):
        if self.worker and self.worker.is_alive(): return
        now=time.time()
        self.attempts=[t for t in self.attempts if now-t<float(self.cfg['retry_window_seconds'])]
        if self.desired!='off':
            if len(self.attempts)>=int(self.cfg['max_attempts']):
                if self.status['phase']!='blocked':
                    self.event('blocked','Versuchslimit erreicht. Neuer Webauftrag startet einen neuen Versuch.')
                    self.notify('Watchdog pausiert nach wiederholten Fehlern. Bitte Webstatus pruefen.')
                return
            self.attempts.append(now)
        self.persist()
        self.cancel.clear()
        self.worker_revision=self.seen_revision
        self.worker=threading.Thread(target=self.transition,args=(self.desired,),daemon=True)
        self.worker.start()

    def accept(self, control):
        rev=int(control['revision']); desired=control['desired']
        if desired not in ('off','flash','27b'): raise ValueError('Ungueltiges Profil')
        if rev == self.seen_revision and desired != self.desired:
            raise ValueError('Profil ohne neue Revision geaendert')
        if rev < self.seen_revision: raise ValueError('Relay-Revision zurueckgesetzt; manuelle Pruefung erforderlich')
        if rev!=self.seen_revision:
            self.cancel.set()
            self.seen_revision=rev; self.desired=desired; self.attempts=[]; self.next_retry=0
            with self.lock: self.status.update(desired=desired,revision=rev)
            self.persist()
            self.event('queued','Webauftrag: '+desired)

    def tick(self, connected):
        if self.worker and self.worker.is_alive(): return
        if self.worker_revision != self.seen_revision or self.status['phase'] in ('queued','waiting','cancelled'):
            if connected: self.launch()
            return
        if self.desired=='off': return
        if self.status['phase'] in ('error','blocked'):
            if time.time()>=self.next_retry: self.launch()
            return
        if time.time()-self.last_health<10: return
        self.last_health=time.time()
        try:
            info=self.inspect()
            ok=info and self.owned(info) and info.get('State',{}).get('Running') and self.api_models()
        except Exception: ok=False
        self.failures=0 if ok else self.failures+1
        if self.failures>=int(self.cfg['health_failures']):
            self.event('recovering','Halogen wiederholt nicht erreichbar; Notfallwiederherstellung')
            self.notify('Halogen nicht erreichbar. Notfallwiederherstellung startet.')
            self.failures=0
            self.launch()

    def run(self):
        while True:
            connected=False
            try:
                data=self.relay(); self.accept(data['control']); connected=True
                with self.lock: self.status['relay_error']=None
            except Exception as e:
                with self.lock: self.status['relay_error']=type(e).__name__+': '+str(e)[:200]
            self.tick(connected)
            with self.lock:
                self.status.update(memory=memory(),agent_ts=int(time.time()),machine=self.cfg['machine_id'])
                snapshot=copy.deepcopy(self.status)
            atomic_json(self.directory/'status.json',snapshot)
            try: self.relay({'kind':'status','status':snapshot})
            except Exception: pass
            time.sleep(float(self.cfg['poll_seconds']))


def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--config',required=True)
    ap.add_argument('--doctor',action='store_true')
    ap.add_argument('--prepare-images',action='store_true')
    args=ap.parse_args()
    cfg=json.loads(Path(args.config).expanduser().read_text())
    if os.getuid()==0: raise SystemExit('Nicht als root starten. Den bisherigen Podman-Benutzer verwenden.')
    s=Supervisor(cfg)
    if args.doctor:
        print(json.dumps({'memory':memory(),'uid':os.getuid(),'podman':s.command(['podman','info','--format','json'],check=False).returncode,
              'devices':{p:os.access(p,os.R_OK|os.W_OK) for p in ['/dev/kfd','/dev/dri']},
              'targets':process_targets(set(cfg['process_names'])),
              'thresholds':{k:target_memory(p,memory()['total_gib']) for k,p in cfg['profiles'].items()}},indent=2))
        return
    if args.prepare_images:
        for p in cfg['profiles'].values():
            s.command(['podman','pull',p['image']],timeout=3600)
        return
    if len(cfg['token'])<32 or cfg['token'].startswith('REPLACE'): raise SystemExit('Eigenes starkes Token konfigurieren')
    if not cfg['relay_url'].startswith('https://'): raise SystemExit('Relay muss HTTPS verwenden')
    lockfile=open(s.directory/'agent.lock','w')
    try: fcntl.flock(lockfile,fcntl.LOCK_EX|fcntl.LOCK_NB)
    except BlockingIOError: raise SystemExit('Recovery-Agent laeuft bereits')
    s.run()

if __name__=='__main__': main()
