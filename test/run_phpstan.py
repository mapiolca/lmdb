#!/usr/bin/env python3
"""Run PHPStan against changed module PHP with real core declarations.
Set DOLIBARR_PATH to the core htdocs directory. The PHAR is a test-only cache.
"""
import json, os, pathlib, subprocess, sys
root = pathlib.Path(__file__).resolve().parent.parent
core = pathlib.Path(os.environ.get('DOLIBARR_PATH', ''))
if not (core / 'core/class/commonobject.class.php').is_file():
    sys.exit('Set DOLIBARR_PATH to a Dolibarr htdocs directory')
cache = root / 'test/.runtime'
cache.mkdir(exist_ok=True)
classes = ['core/class/commonobject.class.php', 'core/class/commondocgenerator.class.php',
           'core/class/commonnumrefgenerator.class.php', 'core/class/hookmanager.class.php',
           'ecm/class/ecmfiles.class.php', 'core/class/link.class.php', 'core/class/html.form.class.php', 'core/class/html.formfile.class.php',
           'core/class/html.formmail.class.php', 'core/class/html.formadmin.class.php',
           'core/class/translate.class.php', 'core/class/commontrigger.class.php', 'core/class/doldeprecationhandler.class.php', 'core/class/conf.class.php', 'core/class/html.formactions.class.php', 'core/class/cemailtemplate.class.php', 'core/db/Database.interface.php', 'main.inc.php', 'includes/tecnickcom/tcpdf/tcpdf.php', 'includes/tcpdi/tcpdi.php', 'includes/restler/framework/Luracast/Restler/RestException.php', 'societe/class/societe.class.php',
           'comm/action/class/actioncomm.class.php','contact/class/contact.class.php','user/class/user.class.php', 'core/db/DoliDB.class.php',
           'core/modules/DolibarrModules.class.php', 'api/class/api.class.php',
           'api/class/api_access.class.php', 'core/triggers/dolibarrtriggers.class.php',
           'comm/mailing/class/mailing.class.php', 'compta/facture/class/facture.class.php']
functions = [core/'core/lib'/name for name in ['functions.lib.php','functions2.lib.php','admin.lib.php','pdf.lib.php','security.lib.php','files.lib.php','ajax.lib.php','company.lib.php','date.lib.php']]
scans = [str(root/'test/phpstan-multicompany.stub')] + [str(p) for p in (core/'core/class').glob('*.php')] + [str(p) for p in (root/'class').glob('*.php')] + [str(p) for p in (root/'lib').glob('*.php')] + [str(core/p) for p in classes if (core/p).is_file()] + [str(p) for p in functions]
paths = [str(p) for pattern in ['attestation_*.php','admin/attestation.php','class/lmdbattestation*.php','class/api_lmdb.class.php','core/modules/lmdb/*.php','core/modules/lmdb/doc/*.php','lib/lmdb_attestation*.php'] for p in root.glob(pattern)]
paths += [str(root/p) for p in ['class/actions_lmdb.class.php','class/lmdbcompatibility.class.php','core/modules/modLmdb.class.php','core/substitutions/functions_lmdb.lib.php','core/triggers/interface_99_modLmdb_LmdbTriggers.class.php','lib/lmdb.lib.php','admin/about.php','admin/compatibility.php']]

config = {'parameters': {'level': 5, 'phpVersion': 80000, 'tmpDir': str(cache/'phpstan-cache'),
                         'bootstrapFiles': [str(root/'test/phpstan_bootstrap.php')],
                         'scanFiles': scans, 'paths': paths}}
# JSON is valid YAML/NEON through PHPStan's NEON parser only for nested values;
# use explicit indentation for predictable configuration parsing.
lines = ['parameters:', '    level: 5', '    phpVersion: 80000', '    tmpDir: ' + json.dumps(str(cache/'phpstan-cache'))]
lines.extend(['    stubFiles:', '        - ' + json.dumps(str(root/'test/phpstan-native.stub'))])
for key in ['bootstrapFiles','scanFiles','paths']:
    lines.append('    '+key+':')
    lines.extend('        - '+json.dumps(p) for p in config['parameters'][key])
(cache/'phpstan.neon').write_text('\n'.join(lines)+'\n',encoding='utf-8')
subprocess.run(['php',str(cache/'phpstan.phar'),'analyse','-c',str(cache/'phpstan.neon'),'--memory-limit=1G','--no-progress','--error-format=table'], cwd=root, check=True)
