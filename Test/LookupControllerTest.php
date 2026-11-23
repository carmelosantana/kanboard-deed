<?php

namespace Kanboard\Plugin\Deed\Test;

use DOMDocument;
use DOMXPath;
use Kanboard\Core\Controller\Runner;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\Security\Role;
use Kanboard\Middleware\ApplicationAuthorizationMiddleware;
use Kanboard\Plugin\Deed\Client\HttpFetcher;
use Kanboard\Plugin\Deed\Client\HttpResponse;
use Kanboard\Plugin\Deed\Plugin;
use KanboardTests\units\Base;
use function Kanboard\Plugin\Deed\Schema\version_1;

require_once 'tests/units/Base.php';

class LookupControllerTest extends Base
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/'.ucfirst(DB_DRIVER).'.php';
        version_1($this->container['db']->getConnection());
        $this->container['route']->enable();
        (new Plugin($this->container))->initialize();

        $this->container['configModel']->save(['deed_last_sweep' => date('Y-m-d')]);
        $fetcher = $this->createMock(HttpFetcher::class);
        $fetcher->expects($this->never())->method('fetch');
        $this->container['deedHttp'] = $fetcher;
    }

    /** @dataProvider networkQueries */
    public function testIpAndAsnQueriesShowRecordDetails(string $query, string $directory, string $path, string $fixture, string $handle): void
    {
        $this->allowFixtures([
            'https://data.iana.org/rdap/'.$directory.'.json' => 'iana-'.$directory.'.json',
            'https://rdap.arin.net/registry/'.$path => $fixture.'.json',
        ]);

        [$status, $body] = $this->dispatch('deed/lookup', Role::APP_MANAGER, ['query' => $query]);

        $this->assertSame(200, $status);
        $this->assertStringContainsString($handle, $body);
        $this->assertStringContainsString('Google LLC', $body);
        $this->assertStringContainsString('network-abuse@google.com', $body);
        $this->assertStringNotContainsString('Add to watchlist', $body);
        $this->assertSame([], $this->container['deedDomainModel']->listAll());
    }

    public static function networkQueries(): iterable
    {
        yield ['8.8.8.8', 'ipv4', 'ip/8.8.8.8', 'ip-arin', 'NET-8-8-8-0-2'];
        yield ['AS15169', 'asn', 'autnum/15169', 'autnum-arin', 'GOOGLE'];
        yield ['15169', 'asn', 'autnum/15169', 'autnum-arin', 'AS15169'];
    }

    public function testIpv6UsesTheIpv6Directory(): void
    {
        $this->allowFixtures(['https://data.iana.org/rdap/ipv6.json' => 'iana-ipv6.json']);

        [$status, $body] = $this->dispatch('deed/lookup', Role::APP_ADMIN, ['query' => '2001:4860::8888']);

        $this->assertSame(200, $status);
        $this->assertStringContainsString('No RDAP service is listed for this IP address', $body);
        $this->assertStringNotContainsString('Add to watchlist', $body);
    }

    /** @dataProvider watchlistStates */
    public function testDomainLookupOffersAnAddFormOnlyForUnwatchedDomains(bool $watched): void
    {
        $model = $this->container['deedDomainModel'];
        if ($watched) {
            $model->create('example.com', 1);
        }
        $this->allowFixtures([
            'https://data.iana.org/rdap/dns.json' => 'iana-dns.json',
            'https://rdap.verisign.com/com/v1/domain/example.com' => 'verisign-com.json',
            'https://cart-before.porkbun.horse/rdap/domain/MADEINNEWBURGH.COM' => 'porkbun-referral.json',
        ]);

        [$status, $body] = $this->dispatch('deed/lookup', Role::APP_ADMIN, ['query' => 'Example.COM.']);

        $this->assertSame(200, $status);
        $this->assertStringContainsString('Porkbun LLC', $body);
        $this->assertStringContainsString('2027-07-31', $body);

        if ($watched) {
            $this->assertStringNotContainsString('Add to watchlist', $body);
            return;
        }

        $this->assertSame([], $model->listAll());
        $document = new DOMDocument();
        $document->loadHTML($body, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);
        $form = $xpath->query('//form[button[normalize-space()="Add to watchlist"]]')->item(0);
        $this->assertNotNull($form);
        $this->assertSame('post', $form->getAttribute('method'));
        $this->assertSame('/deed/domains/add', parse_url($form->getAttribute('action'), PHP_URL_PATH));
        $post = [];
        foreach ($xpath->query('input[@type="hidden"]', $form) as $input) {
            $post[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        $this->assertSame('example.com', $post['domain']);
        $this->assertNotEmpty($post['csrf_token']);

        [$addStatus] = $this->dispatch('deed/domains/add', Role::APP_ADMIN, $post);
        $this->assertSame(303, $addStatus);
        $this->assertSame(['example.com'], array_column($model->listAll(), 'name'));
    }

    public static function watchlistStates(): iterable
    {
        yield [false];
        yield [true];
    }

    public function testDomainRecordRendersWhenStatusesAreMalformed(): void
    {
        $record = json_decode(file_get_contents(__DIR__.'/fixtures/verisign-com.json'), true, 512, JSON_THROW_ON_ERROR);
        $record['status'] = '<script>invalid</script>';
        $record['links'] = [];
        $this->allowFixtures([
            'https://data.iana.org/rdap/dns.json' => 'iana-dns.json',
            'https://rdap.verisign.com/com/v1/domain/example.com' => new HttpResponse(200, json_encode($record, JSON_THROW_ON_ERROR)),
        ]);

        [$status, $body] = $this->dispatch('deed/lookup', Role::APP_ADMIN, ['query' => 'example.com']);

        $this->assertSame(200, $status);
        $this->assertStringContainsString('Porkbun LLC', $body);
        $this->assertStringNotContainsString('invalid</script>', $body);
    }

    public function testUnsupportedDomainReportsMissingRdapService(): void
    {
        $this->allowFixtures(['https://data.iana.org/rdap/dns.json' => 'iana-dns.json']);

        [$status, $body] = $this->dispatch('deed/lookup', Role::APP_ADMIN, ['query' => 'example.invalid']);

        $this->assertSame(200, $status);
        $this->assertStringContainsString('No RDAP service is listed for .invalid', $body);
        $this->assertStringNotContainsString('available', $body);
    }

    /** @dataProvider invalidQueries */
    public function testInvalidQueriesShowValidationWithoutFetching(mixed $query): void
    {
        [$status, $body] = $this->dispatch('deed/lookup', Role::APP_ADMIN, ['query' => $query]);

        $this->assertSame(400, $status);
        $this->assertStringContainsString('Enter a domain name, IP address or ASN.', $body);
        $this->assertStringNotContainsString('<script>invalid</script>', $body);
    }

    public static function invalidQueries(): iterable
    {
        yield ['garbage'];
        yield ['AS4294967296'];
        yield ['999.999.999.999'];
        yield ['<script>invalid</script>'];
        yield [['example.com']];
    }

    public function testLookupFormIsVisibleToManagers(): void
    {
        [$status, $body] = $this->dispatch('deed/lookup', Role::APP_MANAGER);

        $this->assertSame(200, $status);
        $this->assertStringContainsString('name="query"', $body);
        $this->assertStringContainsString('>Lookup</a>', $body);
    }

    public function testAppUserCannotOpenOrSubmitLookup(): void
    {
        foreach ([null, ['query' => 'example.com']] as $post) {
            [$status, $body] = $this->dispatch('deed/lookup', Role::APP_USER, $post);

            $this->assertSame(403, $status);
            $this->assertStringNotContainsString('>Lookup</a>', $body);
        }
    }

    public function testLookupRejectsInvalidCsrf(): void
    {
        [$status] = $this->dispatch('deed/lookup', Role::APP_MANAGER, [
            'query' => 'example.com',
            'csrf_token' => 'invalid',
        ]);

        $this->assertSame(403, $status);
    }

    private function allowFixtures(array $fixtures): void
    {
        $fetcher = $this->createMock(HttpFetcher::class);
        $fetcher->expects($this->exactly(count($fixtures)))->method('fetch')
            ->willReturnCallback(function (string $url) use (&$fixtures): HttpResponse {
                $this->assertArrayHasKey($url, $fixtures);
                $fixture = $fixtures[$url];
                unset($fixtures[$url]);

                return $fixture instanceof HttpResponse
                    ? $fixture
                    : new HttpResponse(200, file_get_contents(__DIR__.'/fixtures/'.$fixture));
            });
        $this->container['deedHttp'] = $fetcher;
    }

    private function dispatch(string $path, string $role, ?array $post = null): array
    {
        $user = $this->container['userModel']->getById(1);
        $user['role'] = $role;
        $this->container['userSession']->initialize($user);
        if ($post !== null && !array_key_exists('csrf_token', $post)) {
            $post['csrf_token'] = $this->container['token']->getCSRFToken();
        }
        $this->container['request'] = new Request($this->container, [
            'REQUEST_URI' => '/'.$path,
            'REQUEST_METHOD' => $post === null ? 'GET' : 'POST',
            'SCRIPT_NAME' => '/index.php',
        ], [], $post ?? []);
        $response = new class($this->container) extends Response {
            public int $code = 200;
            public string $body = '';

            public function withStatusCode($statusCode)
            {
                $this->code = $statusCode;
                return parent::withStatusCode($statusCode);
            }

            public function withBody($body)
            {
                $this->body = $body;
                return parent::withBody($body);
            }

            public function send()
            {
            }
        };
        $this->container['response'] = $response;

        $router = $this->container['router'];
        $router->dispatch();
        $this->assertSame('Deed', $router->getPlugin());

        $runner = new class($this->container) extends Runner {
            protected function executeMiddleware()
            {
                // The test session is already authenticated.
                (new ApplicationAuthorizationMiddleware($this->container))->execute();
            }
        };
        $runner->execute();

        return [$response->code, $response->body];
    }
}
