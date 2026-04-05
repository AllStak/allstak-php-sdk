<?php

declare(strict_types=1);

namespace AllStak\Models;

final class UserContext
{
    public function __construct(
        public readonly string $id = '',
        public readonly string $email = '',
        public readonly string $ip = '',
    ) {}

    public function toArray(): array
    {
        $data = [];
        if ($this->id !== '') {
            $data['id'] = $this->id;
        }
        if ($this->email !== '') {
            $data['email'] = $this->email;
        }
        if ($this->ip !== '') {
            $data['ip'] = $this->ip;
        }
        return $data;
    }

    public function isEmpty(): bool
    {
        return $this->id === '' && $this->email === '' && $this->ip === '';
    }
}
