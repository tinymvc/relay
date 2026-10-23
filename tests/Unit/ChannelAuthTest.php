<?php

namespace Tests\Unit;

use Spark\Relay\ChannelAuth;
use Spark\Testing\TestCase;

class ChannelAuthTest extends TestCase
{
    protected function setUp(): void
    {
        ChannelAuth::clear();
    }

    protected function tearDown(): void
    {
        ChannelAuth::clear();
    }

    public function testAuthorizePrivateChannelMatchingPattern(): void
    {
        $mockUser = (object) ['id' => 42, 'name' => 'Alice'];

        ChannelAuth::define('private-chat.{id}', function ($user, $id) {
            return (int) $id === $user->id;
        });

        // Authorized
        $this->assertTrue(ChannelAuth::authorize($mockUser, 'private-chat.42'));

        // Unauthorized (different id)
        $this->assertFalse(ChannelAuth::authorize($mockUser, 'private-chat.99'));

        // Unmatched channel name
        $this->assertFalse(ChannelAuth::authorize($mockUser, 'private-other.42'));
    }

    public function testAuthorizePresenceChannelReturningRosterData(): void
    {
        $mockUser = (object) ['id' => 10, 'name' => 'Bob'];

        ChannelAuth::define('presence-workspace.{wsId}', function ($user, $wsId) {
            if ($wsId !== 'main') {
                return false;
            }

            return [
                'id' => (string) $user->id,
                'info' => ['name' => $user->name],
            ];
        });

        $result = ChannelAuth::authorize($mockUser, 'presence-workspace.main');
        $this->assertIsArray($result);
        $this->assertEquals('10', $result['id']);
        $this->assertEquals(['name' => 'Bob'], $result['info']);

        $forbidden = ChannelAuth::authorize($mockUser, 'presence-workspace.secret');
        $this->assertFalse($forbidden);
    }
}
