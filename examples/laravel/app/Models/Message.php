<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

// A message of the chat of Ann and Ben, as the app keeps it: what the webhook brought (AppServiceProvider), by the id of its
// room event, and the reactions to it. The chat on the page pages through them (ShopController::messages).
class Message extends Model
{
    protected $fillable = ['event_id', 'from_id', 'text', 'reactions', 'created_at'];

    protected function casts(): array
    {
        return ['from_id' => 'integer', 'reactions' => 'array'];
    }

    /** What the webhook brought from the chat room: a message once, by its room event id, or a reaction to one. */
    public static function keep(array $event): void
    {
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $from = (int) ($event['from'] ?? 0);

        if ('message' === $event['event'] && !empty($event['event_id'])) {
            self::firstOrCreate(['event_id' => $event['event_id']], [
                'from_id' => $from,
                'text' => (string) ($data['text'] ?? ''),
                'reactions' => [],
                'created_at' => Carbon::createFromTimestampMs((int) ($event['at'] ?? 0)),
            ]);
        }

        // A reaction: the one key of the user on the message, an empty key taking it back.
        $message = 'reaction' === $event['event'] ? self::byChatId((string) ($data['id'] ?? '')) : null;
        if (null !== $message) {
            $reactions = [];
            foreach ($message->reactions ?? [] as $key => $users) {
                $kept = array_values(array_filter($users, fn ($user) => $user !== $from));
                if ($kept) {
                    $reactions[(string) $key] = $kept;
                }
            }
            if (is_string($data['key'] ?? null) && '' !== $data['key']) {
                $reactions[$data['key']][] = $from;
            }
            $message->update(['reactions' => $reactions]);
        }
    }

    /** The message the chat names: by its room event id, or `first-<id>` for the two the shop opened with. */
    public static function byChatId(string $id): ?self
    {
        return str_starts_with($id, 'first-') ? self::find((int) substr($id, 6)) : self::firstWhere('event_id', $id);
    }

    /** As the chat on the page shows it. */
    public function toChat(): array
    {
        return [
            'id' => $this->event_id ?? 'first-'.$this->id,
            'from' => $this->from_id,
            'text' => $this->text,
            'at' => $this->created_at->getTimestampMs(),
            'reactions' => (object) ($this->reactions ?? []),
        ];
    }
}
