<?php

namespace Spark\Relay;

use LengthException;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\Message;
use Ratchet\RFC6455\Messaging\MessageInterface;
use Ratchet\RFC6455\Messaging\MessageBuffer as BaseMessageBuffer;

/** Bound fragment metadata as well as payload bytes, including empty fragments. */
class MessageBuffer extends BaseMessageBuffer
{
    public function newMessage(): MessageInterface
    {
        return new class extends Message {
            public function addFrame(FrameInterface $fragment): MessageInterface
            {
                if ($this->count() >= 1024) {
                    throw new LengthException('Too many message fragments');
                }

                return parent::addFrame($fragment);
            }
        };
    }
}
