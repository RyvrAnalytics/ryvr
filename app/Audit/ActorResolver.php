<?php

namespace App\Audit;

use App\Models\ApiKey;
use App\Models\User;
use App\Tenancy\Access;
use Illuminate\Support\Facades\Auth;

final class ActorResolver
{
    private int $commands = 0;

    private int $jobs = 0;

    public function startingCommand(): void
    {
        $this->commands++;
    }

    public function finishedCommand(): void
    {
        $this->commands = max(0, $this->commands - 1);
    }

    public function startingJob(): void
    {
        $this->jobs++;
    }

    public function finishedJob(): void
    {
        $this->jobs = max(0, $this->jobs - 1);
    }

    /** @return array{type: string, id: string|null, metadata: array<string, string>} */
    public function resolve(?string $type = null, ?string $id = null): array
    {
        if ($this->jobs > 0) {
            return $this->describe('system');
        }

        if ($this->commands > 0) {
            return $this->describe('console');
        }

        if ($type !== null) {
            $actor = match ($type) {
                'user' => $id !== null ? User::find($id) : null,
                'api_key' => $id !== null ? ApiKey::find($id) : null,
                default => null,
            };

            return $this->describe($type, $id, $actor);
        }

        $access = request()->attributes->get('access');
        if ($access instanceof Access && $key = $access->apiKey()) {
            return $this->describe('api_key', $key->id, $key);
        }

        $user = Auth::user();

        return $user instanceof User
            ? $this->describe('user', (string) $user->id, $user)
            : $this->describe('system');
    }

    /** @return array{type: string, id: string|null, metadata: array<string, string>} */
    private function describe(string $type, ?string $id = null, User|ApiKey|null $actor = null): array
    {
        $metadata = match (true) {
            $actor instanceof ApiKey => ['api_key_name' => $actor->name],
            $actor instanceof User => ['actor_name' => $actor->name, 'actor_email' => $actor->email],
            default => [],
        };

        return ['type' => $type, 'id' => $id, 'metadata' => $metadata];
    }
}
