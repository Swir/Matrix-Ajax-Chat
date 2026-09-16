<?php
declare(strict_types=1);

namespace MatrixChat;

use PDO;

final class ChatService
{
    private const INVITE = '__SYS_INVITE__';
    private const ACCEPT = '__SYS_ACCEPT__';
    private const DECLINE = '__SYS_DECLINE__';

    public function __construct(private PDO $db, private Crypto $crypto)
    {
    }

    public function touchPresence(string $username): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO online_status (username, last_seen) VALUES (?, ?)
             ON CONFLICT(username) DO UPDATE SET last_seen = excluded.last_seen'
        );
        $stmt->execute([$username, time()]);
    }

    /** @return list<array{name:string,color:string}> */
    public function onlineUsers(string $currentUser): array
    {
        $cutoff = time() - Config::ONLINE_TTL_SECONDS;
        $this->db->prepare('DELETE FROM online_status WHERE last_seen < ?')->execute([$cutoff]);

        $stmt = $this->db->query(
            'SELECT o.username, COALESCE(u.color, "#8ba3c7") AS color
             FROM online_status o
             LEFT JOIN users u ON u.username = o.username
             ORDER BY o.username COLLATE NOCASE'
        );

        $users = [];
        foreach ($stmt->fetchAll() as $row) {
            if ((string)$row['username'] === $currentUser) {
                continue;
            }
            $users[] = [
                'name' => (string)$row['username'],
                'color' => Auth::sanitizeColor((string)$row['color']),
            ];
        }
        return $users;
    }

    public function sendMessage(string $sender, string $color, string $message, string $target): int
    {
        $message = trim($message);
        if ($message === '' || mb_strlen($message) > Config::MAX_MESSAGE_LENGTH) {
            throw new \InvalidArgumentException('message');
        }

        $target = $this->normalizeTarget($target);
        if ($target !== 'global' && !$this->canPrivateChat($sender, $target)) {
            throw new \RuntimeException('private_not_accepted');
        }

        $id = $this->insert($sender, $color, $message, $target);
        $this->prune();
        return $id;
    }

    public function invite(string $sender, string $target): void
    {
        $target = $this->normalizeTarget($target);
        if ($target === 'global' || $target === $sender) {
            throw new \InvalidArgumentException('target');
        }
        $this->insert($sender, '#8ba3c7', $target, self::INVITE);
    }

    public function respondInvite(string $currentUser, string $otherUser, bool $accept): void
    {
        $otherUser = $this->normalizeTarget($otherUser);
        if ($otherUser === 'global' || $otherUser === $currentUser) {
            throw new \InvalidArgumentException('target');
        }

        $this->insert(
            $currentUser,
            '#8ba3c7',
            $otherUser,
            $accept ? self::ACCEPT : self::DECLINE
        );
    }

    /**
     * @return array{
     *   messages:list<array{id:int,time:string,sender:string,color:string,msg:string,is_private:bool}>,
     *   invites:array<string,string>
     * }
     */
    public function messages(string $currentUser, string $target): array
    {
        $target = $this->normalizeTarget($target);
        $rows = $this->db->query(
            'SELECT id, timestamp, sender, color, encrypted_msg, target
             FROM chat_history ORDER BY id DESC LIMIT ' . Config::HISTORY_LIMIT
        )->fetchAll();
        $rows = array_reverse($rows);

        $states = $this->inviteStates($rows, $currentUser);
        $messages = [];

        foreach ($rows as $row) {
            $messageTarget = (string)$row['target'];
            if (in_array($messageTarget, [self::INVITE, self::ACCEPT, self::DECLINE], true)) {
                continue;
            }

            $sender = (string)$row['sender'];
            $allowed = $target === 'global'
                ? $messageTarget === 'global'
                : $messageTarget !== 'global'
                    && in_array($sender, [$target, $currentUser], true)
                    && in_array($messageTarget, [$target, $currentUser], true);

            if (!$allowed) {
                continue;
            }

            $messages[] = [
                'id' => (int)$row['id'],
                'time' => (string)$row['timestamp'],
                'sender' => $sender,
                'color' => Auth::sanitizeColor((string)$row['color']),
                'msg' => $this->crypto->decrypt((string)$row['encrypted_msg']),
                'is_private' => $target !== 'global',
            ];
        }

        return ['messages' => array_slice($messages, -120), 'invites' => $states];
    }

    public function canPrivateChat(string $a, string $b): bool
    {
        $messages = $this->messages($a, 'global');
        return ($messages['invites'][$b] ?? '') === 'accepted';
    }

    /** @param list<array<string,mixed>> $rows
     * @return array<string,string>
     */
    private function inviteStates(array $rows, string $currentUser): array
    {
        $states = [];
        foreach (array_reverse($rows) as $row) {
            $type = (string)$row['target'];
            if (!in_array($type, [self::INVITE, self::ACCEPT, self::DECLINE], true)) {
                continue;
            }

            $sender = (string)$row['sender'];
            $other = $this->crypto->decrypt((string)$row['encrypted_msg']);
            $amSender = $sender === $currentUser;

            if (!$amSender && $other !== $currentUser) {
                continue;
            }

            $peer = $amSender ? $other : $sender;
            if ($peer === '' || isset($states[$peer])) {
                continue;
            }

            if ($type === self::INVITE) {
                $states[$peer] = $amSender ? 'sent' : 'pending';
            } elseif ($type === self::ACCEPT) {
                $states[$peer] = 'accepted';
            } else {
                $states[$peer] = 'declined';
            }
        }

        return $states;
    }

    private function insert(string $sender, string $color, string $message, string $target): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO chat_history (timestamp, sender, color, encrypted_msg, target)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            gmdate('H:i:s'),
            $sender,
            Auth::sanitizeColor($color),
            $this->crypto->encrypt($message),
            $target,
        ]);
        return (int)$this->db->lastInsertId();
    }

    private function normalizeTarget(string $target): string
    {
        $target = trim($target);
        if ($target === '' || $target === 'global') {
            return 'global';
        }

        if (preg_match('/^(?:GUEST_[A-F0-9]{8}|[\p{L}\p{N}_-]{3,24})$/u', $target) !== 1) {
            throw new \InvalidArgumentException('target');
        }
        return $target;
    }

    private function prune(): void
    {
        $this->db->exec(
            'DELETE FROM chat_history
             WHERE id NOT IN (
                SELECT id FROM chat_history ORDER BY id DESC LIMIT ' . Config::HISTORY_LIMIT . '
             )'
        );
    }
}
