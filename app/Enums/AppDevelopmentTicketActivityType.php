<?php

declare(strict_types=1);

namespace App\Enums;

enum AppDevelopmentTicketActivityType: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Assigned = 'assigned';
    case StartedWork = 'started_work';
    case Commented = 'commented';
    case AttachmentAdded = 'attachment_added';
    case SentToQa = 'sent_to_qa';
    case QaRejected = 'qa_rejected';
    case Completed = 'completed';
    case ApkLinked = 'apk_linked';
}
