
@if($site->queue_worker_enabled)
[program:{{ $site->name }}-queue]

command=php artisan queue:work --queue={{ $site->queue_names }} --sleep={{ $site->queue_sleep }} --tries={{ $site->queue_tries }} --max-time={{ $site->queue_max_time }}
directory={{ $projectRoot }}
user={{ $owner }}
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs={{ $site->queue_workers }}

process_name=%(program_name)s_%(process_num)02d
redirect_stderr=true
stdout_logfile={{ config('ldev.home') }}/.config/ldev/logs/{{ $site->name }}-queue.log
stopwaitsecs={{ $site->queue_max_time }}
@endif
@if($site->reverb_enabled)

[program:{{ $site->name }}-reverb]
command=php artisan reverb:start --port={{ $site->reverb_port }}
directory={{ $projectRoot }}
user={{ $owner }}
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=1
redirect_stderr=true
stdout_logfile={{ config('ldev.home') }}/.config/ldev/logs/{{ $site->name }}-reverb.log
stopwaitsecs=10
@endif
@if($site->scheduler_enabled)

[program:{{ $site->name }}-scheduler]
command=php artisan schedule:work
directory={{ $projectRoot }}
user={{ $owner }}
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=1
redirect_stderr=true
stdout_logfile={{ config('ldev.home') }}/.config/ldev/logs/{{ $site->name }}-scheduler.log
stopwaitsecs=10
@endif
@foreach($site->processes->where('enabled', true) as $proc)

[program:{{ $site->name }}-{{ $proc->name }}]
command={!! $proc->supervisorCommand() !!}
directory={{ $projectRoot }}
user={{ $owner }}
autostart={{ $proc->autostart ? 'true' : 'false' }}
autorestart={{ $proc->schedule || $proc->autorestart ? 'true' : 'false' }}
stopasgroup=true
killasgroup=true
numprocs={{ $proc->schedule ? 1 : $proc->numprocs }}
process_name=%(program_name)s_%(process_num)02d
redirect_stderr=true
stdout_logfile={{ config('ldev.home') }}/.config/ldev/logs/{{ $site->name }}-{{ $proc->name }}.log
stopwaitsecs={{ $proc->stopwaitsecs }}
@endforeach
@if(filled($site->supervisor_extra))

; Extra config (Site Detail → Custom background processes → Advanced)
{!! $site->supervisor_extra !!}
@endif
