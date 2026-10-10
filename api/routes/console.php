<?php

use App\Console\Commands\PurgeVoterImports;
use Illuminate\Support\Facades\Schedule;

// Scheduled commands are declared here from slice 07 on (elections open and close by time).

// Slice 08: uploaded voter files and their rows do not stay more than 7 days.
Schedule::command(PurgeVoterImports::class)->daily();
