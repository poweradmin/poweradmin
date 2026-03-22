<?php

namespace unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\RecordComment;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Repository\ApiRecordCommentRepository;

#[CoversClass(ApiRecordCommentRepository::class)]
class ApiRecordCommentRepositoryTest extends TestCase
{
    private MockObject&PowerdnsApiClient $apiClient;
    private MockObject&DnsBackendProvider $backendProvider;
    private ApiRecordCommentRepository $repo;

    private const ZONE_NAME = 'example.com';
    private const DOMAIN_ID = 1;

    protected function setUp(): void
    {
        $this->apiClient = $this->createMock(PowerdnsApiClient::class);
        $this->backendProvider = $this->createMock(DnsBackendProvider::class);

        $this->backendProvider->method('getZoneNameById')
            ->with(self::DOMAIN_ID)
            ->willReturn(self::ZONE_NAME);

        $this->repo = new ApiRecordCommentRepository($this->apiClient, $this->backendProvider);
    }

    private function makeComment(string $name = 'www.example.com', string $type = 'A', string $text = 'test comment'): RecordComment
    {
        return new RecordComment(0, self::DOMAIN_ID, $name, $type, time(), 'admin', $text);
    }

    private function stubZoneData(string $name = 'www.example.com.', string $type = 'A'): void
    {
        $this->apiClient->method('getZone')
            ->willReturn([
                'rrsets' => [
                    [
                        'name' => $name,
                        'type' => $type,
                        'ttl' => 300,
                        'records' => [['content' => '1.2.3.4', 'disabled' => false]],
                        'comments' => [],
                    ]
                ]
            ]);
    }

    #[Test]
    public function addWritesCommentToApi(): void
    {
        $this->stubZoneData();
        $comment = $this->makeComment();

        $this->apiClient->expects($this->once())
            ->method('patchZoneRRsets')
            ->with(
                'example.com.',
                $this->callback(function (array $rrsets) {
                    return $rrsets[0]['comments'][0]['content'] === 'test comment';
                })
            )
            ->willReturn(true);

        $result = $this->repo->add($comment);

        $this->assertSame('test comment', $result->getComment());
    }

    #[Test]
    public function updateWritesCommentToApi(): void
    {
        $this->stubZoneData();
        $comment = $this->makeComment();

        $this->apiClient->expects($this->once())
            ->method('patchZoneRRsets')
            ->willReturn(true);

        $result = $this->repo->update(self::DOMAIN_ID, 'www.example.com', 'A', $comment);

        $this->assertNotNull($result);
        $this->assertSame('test comment', $result->getComment());
    }

    #[Test]
    public function updateClearsOldRRsetOnNameChange(): void
    {
        $this->apiClient->method('getZone')
            ->willReturn([
                'rrsets' => [
                    [
                        'name' => 'www.example.com.',
                        'type' => 'A',
                        'ttl' => 300,
                        'records' => [['content' => '1.2.3.4', 'disabled' => false]],
                        'comments' => [],
                    ],
                    [
                        'name' => 'old.example.com.',
                        'type' => 'A',
                        'ttl' => 300,
                        'records' => [['content' => '5.6.7.8', 'disabled' => false]],
                        'comments' => [['content' => 'old comment', 'account' => '', 'modified_at' => 0]],
                    ],
                ]
            ]);

        $comment = $this->makeComment('www.example.com', 'A', 'updated comment');

        // Expect two API patches: one for the new RRset, one to clear the old
        $this->apiClient->expects($this->exactly(2))
            ->method('patchZoneRRsets')
            ->willReturn(true);

        $this->repo->update(self::DOMAIN_ID, 'old.example.com', 'A', $comment);
    }

    #[Test]
    public function updateReturnsNullWhenZoneNotFound(): void
    {
        $backendProvider = $this->createMock(DnsBackendProvider::class);
        $backendProvider->method('getZoneNameById')->willReturn(null);

        $repo = new ApiRecordCommentRepository($this->apiClient, $backendProvider);
        $comment = $this->makeComment();

        $result = $repo->update(999, 'www.example.com', 'A', $comment);
        $this->assertNull($result);
    }

    #[Test]
    public function addForRecordWritesRRsetComment(): void
    {
        $this->stubZoneData();
        $comment = $this->makeComment();

        $this->apiClient->expects($this->once())
            ->method('patchZoneRRsets')
            ->willReturn(true);

        $result = $this->repo->addForRecord('rec-123', $comment);

        $this->assertNotNull($result);
        $this->assertSame('test comment', $result->getComment());
    }

    #[Test]
    public function addForRecordEmptyCommentClearsApi(): void
    {
        $this->stubZoneData();
        $comment = $this->makeComment('www.example.com', 'A', '');

        $this->apiClient->expects($this->once())
            ->method('patchZoneRRsets')
            ->with(
                'example.com.',
                $this->callback(function (array $rrsets) {
                    return $rrsets[0]['comments'] === [];
                })
            )
            ->willReturn(true);

        $result = $this->repo->addForRecord('rec-123', $comment);
        $this->assertNull($result);
    }

    #[Test]
    public function findReturnsCommentFromApi(): void
    {
        $this->apiClient->method('getZone')
            ->willReturn([
                'rrsets' => [
                    [
                        'name' => 'www.example.com.',
                        'type' => 'A',
                        'ttl' => 300,
                        'records' => [['content' => '1.2.3.4', 'disabled' => false]],
                        'comments' => [['content' => 'found it', 'account' => 'admin', 'modified_at' => 1000]],
                    ]
                ]
            ]);

        $result = $this->repo->find(self::DOMAIN_ID, 'www.example.com', 'A');

        $this->assertNotNull($result);
        $this->assertSame('found it', $result->getComment());
    }

    #[Test]
    public function findReturnsNullWhenNoComment(): void
    {
        $this->stubZoneData(); // has empty comments array

        $result = $this->repo->find(self::DOMAIN_ID, 'www.example.com', 'A');
        $this->assertNull($result);
    }

    #[Test]
    public function findByRecordIdAlwaysReturnsNull(): void
    {
        $result = $this->repo->findByRecordId('rec-123');
        $this->assertNull($result);
    }

    #[Test]
    public function deleteByRecordIdIsNoOp(): void
    {
        $this->apiClient->expects($this->never())->method('patchZoneRRsets');

        $result = $this->repo->deleteByRecordId('rec-123');
        $this->assertTrue($result);
    }

    #[Test]
    public function deleteClearsRRsetComments(): void
    {
        $this->stubZoneData();

        $this->apiClient->expects($this->once())
            ->method('patchZoneRRsets')
            ->with(
                'example.com.',
                $this->callback(function (array $rrsets) {
                    return $rrsets[0]['comments'] === [];
                })
            )
            ->willReturn(true);

        $result = $this->repo->delete(self::DOMAIN_ID, 'www.example.com', 'A');
        $this->assertTrue($result);
    }

    #[Test]
    public function migrateLegacyCommentsReturnsFalse(): void
    {
        $result = $this->repo->migrateLegacyComments(self::DOMAIN_ID, 'www.example.com', 'A', 'rec-123');
        $this->assertFalse($result);
    }
}
