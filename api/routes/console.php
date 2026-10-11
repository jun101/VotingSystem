<?php

use App\Console\Commands\PurgeGeneratedFiles;
use App\Console\Commands\PurgeVoterImports;
use Illuminate\Support\Facades\Schedule;

// Scheduled commands are declared here from slice 07 on (elections open and close by time).

// Slice 08: uploaded voter files and their rows do not stay more than 7 days.
Schedule::command(PurgeVoterImports::class)->daily();

// Slice 10: the slip files go when the election closes; a file stuck queued is marked failed.
Schedule::command(PurgeGeneratedFiles::class)->daily();
