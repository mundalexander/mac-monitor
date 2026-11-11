import importlib.util,json,tempfile,time,unittest,signal
from pathlib import Path
from unittest.mock import patch,MagicMock
BASE=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('recovery',BASE/'client/recovery.py');r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
class Tests(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();self.c=json.loads((BASE/'client/config.example.json').read_text());self.c['state_dir']=self.tmp.name
  for k,p in self.c['profiles'].items():p['models_dir']=self.tmp.name+'/'+k
  self.s=r.Supervisor(self.c);self.s.notify=MagicMock()
 def tearDown(self):self.tmp.cleanup()
 def test_flash_ram(self):self.assertEqual(r.target_memory(self.c['profiles']['flash'],120),112)
 def test_27b_ram(self):self.assertEqual(r.target_memory(self.c['profiles']['27b'],120),64)
 def test_invalid_ram(self):
  for x in [-1,0,999,float('nan'),float('inf')]:
   with self.assertRaises(ValueError):r.target_memory({'min_available_gib':x},120)
 def test_flash_args(self):
  a=self.s.start_args('flash');self.assertNotIn('--rm',a);self.assertIn('--pull=never',a);self.assertIn('127.0.0.1:8731:8731',a)
 def test_27b_args(self):self.assertIn('HALOGEN_TOKENIZER=/models/tokenizer',self.s.start_args('27b'))
 def test_revision(self):
  self.s.accept({'revision':1,'desired':'flash'});self.assertEqual(self.s.desired,'flash');self.assertTrue(self.s.cancel.is_set())
 def test_idempotent(self):
  self.s.accept({'revision':1,'desired':'flash'});self.s.cancel.clear();self.s.accept({'revision':1,'desired':'flash'});self.assertFalse(self.s.cancel.is_set())
 def test_changed_without_revision(self):
  self.s.accept({'revision':1,'desired':'flash'})
  with self.assertRaises(ValueError):self.s.accept({'revision':1,'desired':'27b'})
 def test_revision_rollback(self):
  self.s.accept({'revision':3,'desired':'off'})
  with self.assertRaises(ValueError):self.s.accept({'revision':2,'desired':'flash'})
 def test_arbitrary_profile(self):
  with self.assertRaises(ValueError):self.s.accept({'revision':1,'desired':'shell'})
 def test_persist(self):
  self.s.accept({'revision':4,'desired':'off'});s=r.Supervisor(self.c);self.assertEqual(s.desired,'off');self.assertEqual(s.seen_revision,4)
 def test_off_respected(self):
  self.s.desired='off';self.s.seen_revision=1;self.s.worker_revision=1;self.s.status['phase']='off';self.s.launch=MagicMock();self.s.tick(True);self.s.launch.assert_not_called()
 def test_off_overrides_old_error(self):
  self.s.accept({'revision':2,'desired':'off'});self.s.worker_revision=1;self.s.status['phase']='error';self.s.launch=MagicMock();self.s.tick(True);self.s.launch.assert_called_once()
 def test_initial_connection_required(self):
  self.s.launch=MagicMock();self.s.tick(False);self.s.launch.assert_not_called()
 def test_attempt_limit(self):
  self.s.desired='flash';self.s.attempts=[time.time()]*3;self.s.launch();self.assertEqual(self.s.status['phase'],'blocked');self.assertIsNone(self.s.worker)
 def test_ownership(self):
  self.s.inspect=MagicMock(return_value={'Config':{'Labels':{}}});self.s.command=MagicMock()
  with self.assertRaises(RuntimeError):self.s.stop_container()
  self.s.command.assert_not_called()
 def test_stop_remove(self):
  self.s.inspect=MagicMock(return_value={'Config':{'Labels':{'io.mac-monitor.recovery':'1'}}});self.s.command=MagicMock();self.s.stop_container();self.assertEqual(self.s.command.call_count,2)
 def test_missing_image_no_cleanup(self):
  self.s.command=MagicMock(side_effect=RuntimeError('image missing'));self.s.stop_container=MagicMock();self.s.cleanup=MagicMock();self.s.transition('flash');self.s.stop_container.assert_not_called();self.s.cleanup.assert_not_called();self.assertEqual(self.s.status['phase'],'error')
 def test_enough_ram_no_cleanup(self):
  self.s.command=MagicMock()
  with patch.object(r,'memory',return_value={'total_gib':120,'available_gib':115}):self.s.cleanup(self.c['profiles']['flash'])
  self.s.command.assert_not_called()
 def test_cleanup_permission(self):
  self.c['allow_emergency_cleanup']=False
  with patch.object(r,'memory',return_value={'total_gib':120,'available_gib':10}):
   with self.assertRaises(RuntimeError):self.s.cleanup(self.c['profiles']['flash'])
 def test_pid_reuse(self):
  with patch.object(r,'proc_info',return_value={'uid':r.os.getuid(),'start':'new'}),patch.object(r.os,'kill') as kill:
   self.assertFalse(r.signal_same({'pid':999,'start':'old'},signal.SIGKILL));kill.assert_not_called()
 def test_generic_java(self):self.assertFalse(r.matches_app({'exe':'java','cmd':'java server.jar'},set()))
 def test_android_java(self):self.assertTrue(r.matches_app({'exe':'java','cmd':'java -Didea.platform.prefix=AndroidStudio'},set()))
 def test_generic_electron(self):self.assertFalse(r.matches_app({'exe':'electron','cmd':'electron /opt/other/app'},set()))
 def test_lmstudio_appimage(self):self.assertTrue(r.matches_app({'exe':'LM-Studio-0.4.AppImage','cmd':''},set()))
 def test_cancel(self):
  self.s.cancel.set()
  with self.assertRaises(InterruptedError):self.s.wait(30)
 def test_failed_start_not_ready(self):
  self.s.command=MagicMock(side_effect=RuntimeError('failure'));self.s.transition('27b');self.assertNotEqual(self.s.status['phase'],'ready');self.assertIsNone(self.s.active)
 def test_memory_read(self):self.assertGreater(r.memory()['total_gib'],0)

 def test_cleanup_term_then_kill_and_memory_check(self):
  self.s.command=MagicMock();self.s.wait=MagicMock()
  low={'total_gib':120,'available_gib':10};high={'total_gib':120,'available_gib':115}
  target={'pid':987654,'start':'test','exe':'blender'}
  with patch.object(r,'memory',side_effect=[low,low,high]),patch.object(r,'process_targets',return_value=[target]),patch.object(r,'signal_same') as sig:
   self.s.cleanup(self.c['profiles']['flash'])
   self.assertEqual([x.args[1] for x in sig.call_args_list],[signal.SIGTERM,signal.SIGKILL])
 def test_cleanup_no_kill_if_term_frees_enough(self):
  self.s.command=MagicMock();self.s.wait=MagicMock()
  with patch.object(r,'memory',side_effect=[{'total_gib':120,'available_gib':10},{'total_gib':120,'available_gib':115}]),patch.object(r,'process_targets',return_value=[{'pid':987654,'start':'test','exe':'blender'}]),patch.object(r,'signal_same') as sig:
   self.s.cleanup(self.c['profiles']['flash']);self.assertEqual(sig.call_count,1);self.assertEqual(sig.call_args.args[1],signal.SIGTERM)
 def test_ready_requires_completion(self):
  self.s.command=MagicMock();self.s.stop_container=MagicMock();self.s.cleanup=MagicMock();self.s.wait=MagicMock()
  self.s.inspect=MagicMock(return_value={'State':{'Running':True}});self.s.api_models=MagicMock(return_value='test-model');self.s.completion_probe=MagicMock()
  with patch('socket.socket') as sock,patch.object(r,'memory',return_value={'total_gib':120,'available_gib':115}):
   sock.return_value.__enter__.return_value.connect_ex.return_value=1
   self.s.transition('flash')
  self.s.completion_probe.assert_called_once_with('test-model');self.assertEqual(self.s.status['phase'],'ready');self.assertEqual(self.s.active,'flash')
 def test_completion_failure_never_ready(self):
  self.s.command=MagicMock();self.s.stop_container=MagicMock();self.s.cleanup=MagicMock();self.s.wait=MagicMock()
  self.s.inspect=MagicMock(return_value={'State':{'Running':True}});self.s.api_models=MagicMock(return_value='test-model');self.s.completion_probe=MagicMock(side_effect=RuntimeError('engine stuck'))
  with patch('socket.socket') as sock,patch.object(r,'memory',return_value={'total_gib':120,'available_gib':115}):
   sock.return_value.__enter__.return_value.connect_ex.return_value=1
   self.s.transition('flash')
  self.assertEqual(self.s.status['phase'],'error');self.assertIsNone(self.s.active)

if __name__=='__main__':unittest.main(verbosity=2)
