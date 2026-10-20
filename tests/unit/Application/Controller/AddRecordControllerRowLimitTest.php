<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\AddRecordController;
use Poweradmin\Application\Http\Request;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\FormStateService;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * The multi-row Add Record form: a submit that PHP cut short at max_input_vars, or that
 * carries more rows than the form offers, adds nothing and hands the rows back.
 */
class AddRecordControllerRowLimitTest extends TestCase
{
    private const TRUNCATED = 'No records were added because the form had more fields than the server accepts. Add fewer rows at a time.';

    private array $savedPost = [];

    /** @var array<string, mixed>|null */
    private ?array $saved = null;

    protected function setUp(): void
    {
        $this->savedPost = $_POST;
    }

    protected function tearDown(): void
    {
        $_POST = $this->savedPost;
    }

    public function testASubmitWithoutTheEndMarkerAddsNothing(): void
    {
        $rows = [
            ['name' => 'www', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => '3600', 'reverse' => '1'],
            ['name' => 'mail', 'type' => 'A', 'content' => '192.0.2.2', 'ttl' => '3600'],
        ];

        $redirect = $this->submit(['multi_record_mode' => '1', 'records' => $rows]);

        $this->assertStringStartsWith('/zones/12/records/add?form_id=', $redirect);
        $this->assertSame(self::TRUNCATED, $this->saved['errorMessage'] ?? null);
        $this->assertSame('www', $this->saved['name'] ?? null);
        $this->assertSame(['www', 'mail'], array_column($this->saved['saved_records'] ?? [], 'name'));
        $this->assertTrue($this->saved['saved_records'][0]['reverse']);
    }

    public function testMoreRowsThanTheFormOffersAddsNothingAndHandsBackTheFirstHundred(): void
    {
        $rows = array_map(static fn(int $i): array => ['name' => "h$i", 'type' => 'A', 'content' => '192.0.2.1'], range(1, AddRecordController::MAX_ROWS + 1));

        $this->submit(['multi_record_mode' => '1', 'records' => $rows, 'form_complete' => '1']);

        $this->assertSame(sprintf('At most %d rows can be added at a time.', AddRecordController::MAX_ROWS), $this->saved['errorMessage'] ?? null);
        $this->assertCount(AddRecordController::MAX_ROWS, $this->saved['saved_records'] ?? []);
    }

    public function testACompleteSubmitWithinTheLimitGoesOnToAddTheRows(): void
    {
        $rows = array_map(static fn(int $i): array => ['name' => "h$i", 'type' => 'A', 'content' => '192.0.2.1'], range(1, AddRecordController::MAX_ROWS));

        // The zone lookup that starts the adding is stubbed to stop the request there
        $this->assertSame('Zone not found.', $this->submit(['multi_record_mode' => '1', 'records' => $rows, 'form_complete' => '1']));
        $this->assertNull($this->saved);
    }

    /**
     * @param array<string, mixed> $post
     * @return string The redirect target, or the error the request stopped with
     */
    private function submit(array $post): string
    {
        $_POST = $post;

        $controller = new class (['zone_id' => '12']) extends AddRecordController {
            use ControllerTestStubs;

            public ?string $redirectedTo = null;

            public function redirect(string $url, array $args = []): void
            {
                $this->redirectedTo = $url;
            }
        };

        $forms = $this->createMock(FormStateService::class);
        $forms->method('generateFormId')->willReturn('add_record_x');
        $forms->method('saveFormData')->willReturnCallback(function (string $formId, array $data): void {
            $this->saved = $data;
        });
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainNameById')->willReturn(null);

        foreach (['request' => new Request(), 'formStateService' => $forms, 'domainRepository' => $domains] as $name => $value) {
            (new ReflectionProperty(AddRecordController::class, $name))->setValue($controller, $value);
        }

        try {
            (new ReflectionMethod(AddRecordController::class, 'addMultipleRecords'))->invoke($controller);
        } catch (RuntimeException $halt) {
            return $halt->getMessage();
        }

        return (string)$controller->redirectedTo;
    }
}
