<?php

namespace Laravel\Telescope\Tests\Watchers;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\Tests\FeatureTestCase;
use Laravel\Telescope\Watchers\RequestWatcher;

class RequestWatchersTest extends FeatureTestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app->get('config')->set('telescope.watchers', [
            RequestWatcher::class => [
                'enabled' => true,
                'ignore_http_methods' => ['PATCH'],
                'ignore_status_codes' => [204],
                'ignore_paths' => ['ignore-uri'],
            ],
        ]);

        if (! defined('LARAVEL_START')) {
            define('LARAVEL_START', microtime(true));
        }
    }

    public function test_request_watcher_registers_requests()
    {
        Route::get('/emails', function () {
            return ['email' => 'themsaid@laravel.com'];
        });

        $this->get('/emails')->assertSuccessful();

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::REQUEST, $entry->type);
        $this->assertSame('GET', $entry->content['method']);
        $this->assertSame(200, $entry->content['response_status']);
        $this->assertSame('/emails', $entry->content['uri']);
    }

    public function test_request_watcher_registers_404()
    {
        $this->get('/whatever');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::REQUEST, $entry->type);
        $this->assertSame('GET', $entry->content['method']);
        $this->assertSame(404, $entry->content['response_status']);
        $this->assertSame('/whatever', $entry->content['uri']);
    }

    public function test_request_watcher_hides_password()
    {
        Route::post('/auth', function () {
            return response('success');
        });

        $this->post('/auth', [
            'email' => 'telescope@laravel.com',
            'password' => 'secret',
            'password_confirmation' => 'secret',
        ]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::REQUEST, $entry->type);
        $this->assertSame('POST', $entry->content['method']);
        $this->assertSame('telescope@laravel.com', $entry->content['payload']['email']);
        $this->assertSame('********', $entry->content['payload']['password']);
        $this->assertSame('********', $entry->content['payload']['password_confirmation']);
    }

    public function test_request_watcher_hides_authorization()
    {
        Route::post('/dashboard', function () {
            return response('success');
        });

        $this->post('/dashboard', [], [
            'Authorization' => 'Basic YWxhZGRpbjpvcGVuc2VzYW1l',
            'Content-Type' => 'application/json',
        ]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::REQUEST, $entry->type);
        $this->assertSame('POST', $entry->content['method']);
        $this->assertSame('application/json', $entry->content['headers']['content-type']);
        $this->assertSame('********', $entry->content['headers']['authorization']);
    }

    public function test_request_watcher_hides_php_auth_pw()
    {
        Route::post('/dashboard', function () {
            return response('success');
        });

        $this->post('/dashboard', [], [
            'php-auth-pw' => 'secret',
        ]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::REQUEST, $entry->type);
        $this->assertSame('POST', $entry->content['method']);
        $this->assertSame('********', $entry->content['headers']['php-auth-pw']);
    }

    public function test_it_stores_and_displays_array_of_request_headers()
    {
        Route::post('/dashboard', function () {
            return response('success')->withHeaders([
                'X-Foo' => ['first', 'second'],
            ]);
        });

        $this->post('/dashboard', [], [
            'X-Bar' => ['first', 'second'],
        ]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::REQUEST, $entry->type);
        $this->assertSame('first, second', $entry->content['headers']['x-bar']);
    }

    public function test_request_watcher_handles_file_uploads()
    {
        $image = UploadedFile::fake()->image('avatar.jpg');

        $this->post('fake-upload-file-route', [
            'image' => $image,
        ]);

        $uploadedImage = $this->loadTelescopeEntries()->first()->content['payload']['image'];

        $this->assertSame($image->getClientOriginalName(), $uploadedImage['name']);

        $this->assertSame($image->getSize() / 1000 .'KB', $uploadedImage['size']);
    }

    public function test_request_watcher_handles_unlinked_file_uploads()
    {
        $image = UploadedFile::fake()->image('unlinked-image.jpg');

        unlink($image->getPathName());

        $this->post('fake-upload-file-route', [
            'unlinked-image' => $image,
        ]);

        $uploadedImage = $this->loadTelescopeEntries()->first()->content['payload']['unlinked-image'];

        $this->assertSame($image->getClientOriginalName(), $uploadedImage['name']);

        $this->assertSame('0', $uploadedImage['size']);
    }

    public function test_request_watcher_plain_text_response()
    {
        Route::get('/fake-plain-text', function () {
            return Response::make(
                'plain telescope response', 200, ['Content-Type' => 'text/plain']
            );
        });

        $this->get('/fake-plain-text')->assertSuccessful();

        $entry = $this->loadTelescopeEntries()->first();
        $this->assertSame(EntryType::REQUEST, $entry->type);
        $this->assertSame('GET', $entry->content['method']);
        $this->assertSame(200, $entry->content['response_status']);
        $this->assertSame('plain telescope response', $entry->content['response']);
    }

    public function test_request_watcher_ignores_specified_methods()
    {
        Route::patch('/ignore-method', function () {
            return Response::make(
                'ignored method response', 200, ['Content-Type' => 'text/plain']
            );
        });

        $this->patch('/ignore-method')->assertSuccessful();

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertNull($entry);
    }

    public function test_request_watcher_ignores_specified_status_code()
    {
        Route::get('/ignore-status-code', function () {
            return Response::make(
                'ignored status code response', 204, ['Content-Type' => 'text/plain']
            );
        });

        $this->get('/ignore-status-code')->assertStatus(204);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertNull($entry);
    }

    public function test_request_watcher_ignores_specified_uri()
    {
        Route::get('/ignore-uri', function () {
            return Response::make(
                'ignored uri response', 200, ['Content-Type' => 'text/plain']
            );
        });

        $this->get('/ignore-uri')->assertSuccessful();

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertNull($entry);
    }
}
