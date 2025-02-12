<?php

declare(strict_types=1);

namespace Ddeboer\Imap\Tests;

use Ddeboer\Imap\Exception\AbstractException;
use Ddeboer\Imap\Exception\InvalidSearchCriteriaException;
use Ddeboer\Imap\Exception\MessageCopyException;
use Ddeboer\Imap\Exception\MessageDoesNotExistException;
use Ddeboer\Imap\Exception\MessageMoveException;
use Ddeboer\Imap\Exception\RenameMailboxException;
use Ddeboer\Imap\ImapResource;
use Ddeboer\Imap\Mailbox;
use Ddeboer\Imap\MailboxInterface;
use Ddeboer\Imap\MessageIterator;
use Ddeboer\Imap\MessageIteratorInterface;
use Ddeboer\Imap\Search;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AbstractException::class)]
#[CoversClass(ImapResource::class)]
#[CoversClass(Mailbox::class)]
final class MailboxTest extends AbstractTestCase
{
    private MailboxInterface $mailbox;

    protected function setUp(): void
    {
        $this->mailbox = $this->createMailbox();

        $this->createTestMessage($this->mailbox, 'Message 1');
        $this->createTestMessage($this->mailbox, 'Message 2');
        $this->createTestMessage($this->mailbox, 'Message 3');
    }

    public function testGetName(): void
    {
        self::assertSame($this->mailboxName, $this->mailbox->getName());
    }

    public function testRenameTo(): void
    {
        self::assertNotSame($this->mailboxName, $this->altName);

        /** @var string $altName */
        $altName = $this->altName;
        self::assertTrue($this->mailbox->renameTo($altName));
        self::assertSame($this->altName, $this->mailbox->getName());

        /** @var string $mailboxName */
        $mailboxName = $this->mailboxName;
        self::assertTrue($this->mailbox->renameTo($mailboxName));
        self::assertSame($this->mailboxName, $this->mailbox->getName());

        $this->expectException(RenameMailboxException::class);
        $this->mailbox->renameTo($mailboxName);
    }

    public function testGetFullEncodedName(): void
    {
        self::assertIsString($this->mailboxName);

        $fullEncodedName = $this->mailbox->getFullEncodedName();
        self::assertStringContainsString((string) \getenv('IMAP_SERVER_PORT'), $fullEncodedName);
        self::assertStringNotContainsString($this->mailboxName, $fullEncodedName);
        self::assertStringContainsString(\mb_convert_encoding($this->mailboxName, 'UTF7-IMAP', 'UTF-8'), $fullEncodedName);
        self::assertStringNotContainsString(':' . \getenv('IMAP_SERVER_PORT'), $this->mailbox->getEncodedName());
    }

    public function testGetAttributes(): void
    {
        self::assertGreaterThan(0, $this->mailbox->getAttributes());
    }

    public function testGetDelimiter(): void
    {
        self::assertNotEmpty($this->mailbox->getDelimiter());
    }

    public function testGetMessages(): void
    {
        $directMethodInc = 0;
        foreach ($this->mailbox->getMessages() as $message) {
            ++$directMethodInc;
        }

        self::assertSame(3, $directMethodInc);

        $aggregateIteratorMethodInc = 0;
        foreach ($this->mailbox as $message) {
            ++$aggregateIteratorMethodInc;
        }

        self::assertSame(3, $aggregateIteratorMethodInc);
    }

    public function testGetMessageSequence(): void
    {
        $inc = 0;
        foreach ($this->mailbox->getMessageSequence('1:*') as $message) {
            ++$inc;
        }
        self::assertSame(3, $inc);

        $inc = 0;
        foreach ($this->mailbox->getMessageSequence('1:2') as $message) {
            ++$inc;
        }

        self::assertSame(2, $inc);
        $inc = 0;
        foreach ($this->mailbox->getMessageSequence('99998:99999') as $message) {
            ++$inc;
        }
        self::assertSame(0, $inc);
    }

    public function testGetMessageSequenceThrowsException(): void
    {
        $this->expectException(InvalidSearchCriteriaException::class);
        $this->mailbox->getMessageSequence('-1:x');
    }

    public function testGetMessageThrowsException(): void
    {
        $message = $this->mailbox->getMessage(999);

        $this->expectException(MessageDoesNotExistException::class);
        $this->expectExceptionMessageMatches('/Message "999" does not exist/');

        $message->isRecent();
    }

    public function testCount(): void
    {
        self::assertSame(3, $this->mailbox->count());
    }

    public function testDefaultStatus(): void
    {
        $status = $this->mailbox->getStatus();

        self::assertSame(\SA_ALL, $status->flags);
        self::assertSame(3, $status->messages);
        self::assertSame(4, $status->uidnext);
    }

    public function testCustomStatusFlag(): void
    {
        $status = $this->mailbox->getStatus(\SA_MESSAGES);

        self::assertSame(\SA_MESSAGES, $status->flags);
        self::assertSame(3, $status->messages);
        self::assertFalse(isset($status->uidnext), 'uidnext shouldn\'t be set');
    }

    public function testBulkSetFlags(): void
    {
        // prepare second mailbox with 3 messages
        $anotherMailbox = $this->createMailbox();
        $this->createTestMessage($anotherMailbox, 'Message 1');
        $this->createTestMessage($anotherMailbox, 'Message 2');
        $this->createTestMessage($anotherMailbox, 'Message 3');

        // Message UIDs created in setUp method
        $messages = [1, 2, 3];

        foreach ($messages as $uid) {
            $message = $this->mailbox->getMessage($uid);
            self::assertFalse($message->isFlagged());
        }

        $this->mailbox->setFlag('\\Flagged', $messages);

        foreach ($messages as $uid) {
            $message = $this->mailbox->getMessage($uid);
            self::assertTrue($message->isFlagged());
        }

        $this->mailbox->clearFlag('\\Flagged', $messages);

        foreach ($messages as $uid) {
            $message = $this->mailbox->getMessage($uid);
            self::assertFalse($message->isFlagged());
        }

        // Set flag for messages from another mailbox
        $anotherMailbox->setFlag('\\Flagged', [1, 2, 3]);

        self::assertTrue($anotherMailbox->getMessage(2)->isFlagged());
    }

    public function testBulkSetFlagsNumbersParameter(): void
    {
        $mailbox = $this->createMailbox();

        $uids = \range(1, 10);

        foreach ($uids as $uid) {
            $this->createTestMessage($mailbox, 'Message ' . $uid);
        }

        $mailbox->setFlag('\\Seen', [
            '1,2',
            '3',
            '4:6',
        ]);
        $mailbox->setFlag('\\Seen', '7,8:10');

        foreach ($uids as $uid) {
            $message = $mailbox->getMessage($uid);
            self::assertTrue($message->isSeen());
        }

        $mailbox->clearFlag('\\Seen', '1,2,3,4:6');
        $mailbox->clearFlag('\\Seen', [
            '7:9',
            '10',
        ]);

        foreach ($uids as $uid) {
            $message = $mailbox->getMessage($uid);
            self::assertFalse($message->isSeen());
        }
    }

    public function testThread(): void
    {
        $mailboxOne = $this->createMailbox();
        $mailboxOne->addMessage($this->getFixture('plain_only'));
        $mailboxOne->addMessage($this->getFixture('thread/my_topic'));
        $mailboxOne->addMessage($this->getFixture('thread/unrelated'));
        $mailboxOne->addMessage($this->getFixture('thread/re_my_topic'));

        // Add and remove the first message to test SE_UID
        foreach ($mailboxOne as $message) {
            $message->delete();

            break;
        }
        $this->getConnection()->expunge();

        $expected = [
            '0.num'    => 2,
            '0.next'   => 1,
            '1.num'    => 4,
            '1.next'   => 0,
            '1.branch' => 0,
            '0.branch' => 2,
            '2.num'    => 3,
            '2.next'   => 0,
            '2.branch' => 0,
        ];

        self::assertSame($expected, $mailboxOne->getThread());

        $emptyMailbox = $this->createMailbox();

        self::assertEmpty($emptyMailbox->getThread());
    }

    public function testAppendOptionalArguments(): void
    {
        $mailbox = $this->createMailbox();

        $mailbox->addMessage($this->getFixture('thread/unrelated'), '\\Seen', new \DateTimeImmutable('2012-01-03T10:30:03+01:00'));

        $message = $mailbox->getMessage(1);

        self::assertTrue($message->isSeen());
        self::assertSame(' 3-Jan-2012 09:30:03 +0000', $message->getHeaders()->get('maildate'));
    }

    public function testBulkMove(): void
    {
        $anotherMailbox = $this->createMailbox();

        // Test move by id
        $messages = [1, 2, 3];

        self::assertSame(0, $anotherMailbox->count());
        $this->mailbox->move($messages, $anotherMailbox);
        $this->getConnection()->expunge();

        self::assertSame(3, $anotherMailbox->count());
        self::assertSame(0, $this->mailbox->count());

        // move back by iterator
        /** @var MessageIterator $messages */
        $messages = $anotherMailbox->getMessages();
        $anotherMailbox->move($messages, $this->mailbox);
        $this->getConnection()->expunge();

        self::assertSame(0, $anotherMailbox->count());
        self::assertSame(3, $this->mailbox->count());

        // Somehow mailbox deleting in Dovecot in Github CI doesn't work :\
        if (false !== \getenv('CI')) {
            return;
        }
        // test failing bulk move - try to move to a non-existent mailbox
        $this->getConnection()->deleteMailbox($anotherMailbox);
        $this->expectException(MessageMoveException::class);
        $this->mailbox->move($messages, $anotherMailbox);
    }

    public function testBulkCopy(): void
    {
        $anotherMailbox = $this->createMailbox();
        $messages       = [1, 2, 3];

        self::assertSame(0, $anotherMailbox->count());
        self::assertSame(3, $this->mailbox->count());
        $this->mailbox->copy($messages, $anotherMailbox);

        self::assertSame(3, $anotherMailbox->count());
        self::assertSame(3, $this->mailbox->count());

        // test failing bulk copy - try to move to a non-existent mailbox
        $this->getConnection()->deleteMailbox($anotherMailbox);
        $this->expectException(MessageCopyException::class);
        $this->mailbox->copy($messages, $anotherMailbox);
    }

    public function testSort(): void
    {
        $anotherMailbox = $this->createMailbox();
        $this->createTestMessage($anotherMailbox, 'B');
        $this->createTestMessage($anotherMailbox, 'A');
        $this->createTestMessage($anotherMailbox, 'C');

        $concatSubjects = static function (MessageIteratorInterface $it): string {
            $subject    = '';
            foreach ($it as $message) {
                $subject .= $message->getSubject();
            }

            return $subject;
        };

        self::assertSame('BAC', $concatSubjects($anotherMailbox->getMessages()));
        self::assertSame('ABC', $concatSubjects($anotherMailbox->getMessages(null, \SORTSUBJECT)));
        self::assertSame('CBA', $concatSubjects($anotherMailbox->getMessages(null, \SORTSUBJECT, true)));
        self::assertSame('B', $concatSubjects($anotherMailbox->getMessages(new Search\Text\Subject('B'), \SORTSUBJECT, true)));
    }

    public function testGetMessagesWithUtf8Subject(): void
    {
        $anotherMailbox = $this->createMailbox();
        $this->createTestMessage($anotherMailbox, '1', 'Ж П');
        $this->createTestMessage($anotherMailbox, '2', 'Ж б');
        $this->createTestMessage($anotherMailbox, '3', 'б П');

        $messagesFound = '';
        foreach ($anotherMailbox->getMessages(new Search\Text\Body(\mb_convert_encoding('б', 'Windows-1251', 'UTF-8')), null, false, 'Windows-1251') as $message) {
            $subject = $message->getSubject();
            self::assertIsString($subject);

            $messagesFound .= \substr($subject, 0, 1);
        }

        self::assertSame('23', $messagesFound);

        $messagesFound = '';
        foreach ($anotherMailbox->getMessages(new Search\Text\Body(\mb_convert_encoding('П', 'Windows-1251', 'UTF-8')), \SORTSUBJECT, true, 'Windows-1251') as $message) {
            $subject = $message->getSubject();
            self::assertIsString($subject);

            $messagesFound .= \substr($subject, 0, 1);
        }

        self::assertSame('31', $messagesFound);
    }
}
