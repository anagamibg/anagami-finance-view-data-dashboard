<?php

namespace App\Notifications\Databackd;

use App\Models\Databackd\FinAlertEvent;
use App\Notifications\BaseNotification;
use App\Services\Databackd\Gaps\Alerts\AlertText;

/**
 * In-app (bell) notification of a fired alert rule, sent to the company admins by AlertChecker.
 * Channel: database only - the e-mail of an alert is FinAlertMail (it also goes to additional recipients who are
 * not users). The text is rendered in the locale of every recipient from the event context.
 *
 * The bell shows it with resources/views/notifications/all/fin_alert_notification.blade.php
 * (the view name is the snake case class name).
 */
class FinAlertNotification extends BaseNotification
{

    private int $eventId;
    private ?int $ruleId;
    private string $severity;
    private string $message;
    private array $context;
    private ?string $periodFrom;
    private ?string $periodTo;

    public function __construct(FinAlertEvent $event, private string $ruleName)
    {
        $this->eventId = (int)$event->id;
        $this->ruleId = $event->rule_id ? (int)$event->rule_id : null;
        $this->severity = (string)$event->severity;
        $this->message = (string)$event->message;
        $this->context = (array)$event->context;
        $this->periodFrom = $event->period_from?->toDateString();
        $this->periodTo = $event->period_to?->toDateString();
    }

    public function via($notifiable)
    {
        return ['database'];
    }

    // phpcs:ignore
    public function toArray($notifiable)
    {
        $locale = $notifiable->locale ?? null;

        $text = AlertText::message(
            $this->context,
            $this->periodFrom ? \Carbon\Carbon::parse($this->periodFrom) : null,
            $this->periodTo ? \Carbon\Carbon::parse($this->periodTo) : null,
            $locale
        );

        return [
            'id' => $this->eventId,
            'event_id' => $this->eventId,
            'rule_id' => $this->ruleId,
            'severity' => $this->severity,
            'title' => $this->ruleName,
            'message' => $text ?? $this->message,
            'url' => route('databackd.gaps.alerts.history', ['event' => $this->eventId], false),
        ];
    }

}
