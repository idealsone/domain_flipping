<?php

namespace App\Services;

/**
 * 3.75.0 A small read-only IMAP client (no PHP imap extension needed): sign in, open a folder read-only (EXAMINE),
 * search by UID or date and fetch whole messages. It never stores, moves, flags or deletes anything.
 */
class ImapReader
{
    /** @var resource */
    private $s;

    private int $n = 0;

    private int $uidNext = 0;

    public function __construct(string $host, int $port, int $timeout = 20)
    {
        $scheme = $port === 993 ? 'ssl' : 'tcp';
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $s = @stream_socket_client("$scheme://$host:$port", $errno, $err, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (! $s) {
            throw new \RuntimeException("Could not connect to $host:$port ($err)");
        }
        stream_set_timeout($s, $timeout);
        $this->s = $s;
        $hello = $this->line();
        if (! str_starts_with($hello, '* OK')) {
            throw new \RuntimeException('Could not connect: unexpected greeting');
        }
    }

    private function line(): string
    {
        $l = fgets($this->s, 65536);
        if ($l === false) {
            $meta = stream_get_meta_data($this->s);
            throw new \RuntimeException($meta['timed_out'] ?? false ? 'The mail server did not answer in time (connect)' : 'The mail server closed the connection (connect)');
        }

        return $l;
    }

    /** Sends one command; returns the untagged lines, with each literal joined into its line. */
    private function cmd(string $c): array
    {
        $tag = 'A'.(++$this->n);
        fwrite($this->s, "$tag $c\r\n");
        $out = [];
        while (true) {
            $l = $this->line();
            while (preg_match('/\{(\d+)\}\r?\n$/', $l, $m)) {
                $want = (int) $m[1];
                $data = '';
                while (strlen($data) < $want) {
                    $chunk = fread($this->s, min(65536, $want - strlen($data)));
                    if ($chunk === false || $chunk === '') {
                        throw new \RuntimeException('The mail server closed the connection while sending a message');
                    }
                    $data .= $chunk;
                }
                $l = substr($l, 0, -strlen($m[0])).'{'.$want.'}'."\0".$data."\0".$this->line();
            }
            if (str_starts_with($l, $tag.' ')) {
                if (! preg_match('/^'.$tag.' OK/i', $l)) {
                    throw new \RuntimeException(strtoupper(strtok($c, ' ')).' failed: '.trim(substr($l, strlen($tag) + 1)));
                }

                return $out;
            }
            $out[] = $l;
        }
    }

    private static function quote(string $s): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $s).'"';
    }

    public function login(string $user, string $password): void
    {
        try {
            $this->cmd('LOGIN '.self::quote($user).' '.self::quote($password));
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('AUTH: '.$e->getMessage());
        }
    }

    /** Opens a folder read-only; returns its UIDVALIDITY. */
    public function examine(string $folder): string
    {
        $validity = '';
        foreach ($this->cmd('EXAMINE '.self::quote($folder)) as $l) {
            if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $l, $m)) {
                $validity = $m[1];
            }
            if (preg_match('/\[UIDNEXT (\d+)\]/i', $l, $m)) {
                $this->uidNext = (int) $m[1];
            }
        }

        return $validity;
    }

    public function uidNext(): int
    {
        return $this->uidNext;
    }

    /** @return int[] */
    public function search(string $criteria): array
    {
        $ids = [];
        foreach ($this->cmd('UID SEARCH '.$criteria) as $l) {
            if (preg_match('/^\* SEARCH\b(.*)$/i', trim($l), $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) as $x) {
                    if (ctype_digit($x)) {
                        $ids[] = (int) $x;
                    }
                }
            }
        }

        return $ids;
    }

    /** The raw message (up to $max bytes), without setting the Seen flag. */
    public function fetch(int $uid, int $max): string
    {
        foreach ($this->cmd("UID FETCH $uid (BODY.PEEK[]<0.$max>)") as $l) {
            if (preg_match('/BODY\[\](?:<0>)? \{\d+\}\0(.*)\0/s', $l, $m)) {
                return $m[1];
            }
        }

        return '';
    }

    public function logout(): void
    {
        try {
            $this->cmd('LOGOUT');
        } catch (\Throwable $e) {
            // the server may close first
        }
        $this->close();
    }

    public function close(): void
    {
        if (is_resource($this->s)) {
            fclose($this->s);
        }
    }
}
