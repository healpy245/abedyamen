import paramiko
from sftp_config import load_sftp

host, port, user, password = load_sftp()
transport = paramiko.Transport((host, port))
transport.connect(username=user, password=password)
sftp = paramiko.SFTPClient.from_transport(transport)
log_dir = '/home/tut8x1radmrp/webtimize/storage/logs'
names = sorted(sftp.listdir(log_dir))
print('logs', names[-5:])
latest = names[-1]
with sftp.open(log_dir + '/' + latest) as handle:
    data = handle.read().decode('utf-8', errors='replace')
tail = data[-8000:]
# keep only lines that mention apps, Mansour, Error, or ensure
for line in tail.splitlines():
    if any(word in line for word in ('Error', 'Exception', 'ensureApps', 'AppsPrompt', 'منصور')):
        print(line[:300])
print('--- tail end ---')
print('\n'.join(tail.splitlines()[-15:]))
sftp.close()
transport.close()
