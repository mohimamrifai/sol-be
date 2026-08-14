<?php

declare(strict_types=1);

namespace App\Enums;

enum TrainScheduleStatus: string
{
    case Upcoming = 'upcoming';
    case Departed = 'departed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
