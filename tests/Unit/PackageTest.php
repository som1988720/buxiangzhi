<?php
/**
 * @author Aaron Francis <aaron@hammerstone.dev>
 */

namespace Hammerstone\Sidecar\Tests\Unit;

use Hammerstone\Sidecar\Package;
use Hammerstone\Sidecar\Tests\Unit\Support\FakeStreamWrapper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Mockery;

class PackageTest extends BaseTest
{
    public function getEnvironmentSetUp($app)
    {
        Carbon::setTestNow('2021-01-01 00:00:00');

        config()->set('sidecar', [
            'aws_key' => 'key',
            'aws_secret' => 'secret',
            'aws_region' => 'us-east-2',
            'aws_bucket' => 'sidecar-bucket',
        ]);
    }

    public function makePackageClass()
    {
        Storage::fake();
        FakeStreamWrapper::reset();

        $package = Mockery::mock(Package::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $package->setBasePath(__DIR__);

        $package->shouldReceive('registerStreamWrapper')->andReturnUsing(function () {
            FakeStreamWrapper::register();
        });

        return $package;
    }

    /** @test */
    public function an_exclamation_excludes()
    {
        $package = Package::make([
            'include',
            '!exclude',
        ]);

        $this->assertCount(1, $package->getIncludedPaths());
        $this->assertStringContainsString('include', $package->getIncludedPaths()[0]);

        $this->assertCount(1, $package->getExcludedPaths());
        $this->assertStringContainsString('exclude', $package->getExcludedPaths()[0]);
    }

    /** @test */
    public function it_includes_an_entire_directory()
    {
        $package = $this->makePackageClass();

        $package->include([
            'Support/Files'
        ]);

        $files = $package->files();

        $this->assertEquals(3, $files->count());

        $files = $files
            ->map(function ($file) {
                return last(explode('/', $file));
            })
            ->sort()
            ->values()
            ->toArray();

        $this->assertEquals([
            'file1.txt',
            'file2.txt',
            'file3.txt',
        ], $files);
    }

    /** @test */
    public function it_sets_the_base_path_correctly()
    {
        $package = $this->makePackageClass();

        $package->include([
            'Support/Files'
        ]);

        $files = $package->files();

        foreach ($files as $file) {
            $this->assertStringStartsWith(__DIR__, $file);
        }
    }

    /** @test */
    public function start_includes_everything_in_base_path()
    {
        $package = $this->makePackageClass();

        $package->setBasePath(__DIR__ . '/Support/Files');
        $package->include('*');

        $this->assertCount(3, $package->files());
    }

    /** @test */
    public function base_path_order()
    {
        // base_path by default.
        $package = new Package;
        $this->assertEquals(base_path(), $package->getBasePath());

        config(['sidecar.package_base_path' => base_path('by-config')]);

        // Config overrules default.
        $package = new Package;
        $this->assertEquals(base_path('by-config'), $package->getBasePath());

        // Direct set overrules everything
        $package = new Package;
        $package->setBasePath(base_path('direct-set'));
        $this->assertEquals(base_path('direct-set'), $package->getBasePath());
    }

    /** @test */
    public function it_excludes_files()
    {
        $package = $this->makePackageClass();

        $package->include([
            'Support/Files'
        ]);

        $package->exclude([
            'Support/Files/file1.txt'
        ]);

        $files = $package->files();

        $this->assertEquals(2, $files->count());

        foreach ($files as $file) {
            $this->assertStringNotContainsString('file1.txt', $file);
        }
    }

    /** @test */
    public function can_add_exact_files()
    {
        $package = $this->makePackageClass();

        $package->include([
            'Support/Files'
        ]);

        $package->includeExactly([
            __DIR__ . '/Support/Files/file1.txt' => 'root.txt'
        ]);

        file_put_contents(__DIR__ . '/Support/Files/file1.txt', '1');

        $package->upload();

        $contents = head(FakeStreamWrapper::$paths);

        file_put_contents(__DIR__ . '/Support/Files/file1.txt', '');

        // Write the contents to disk to inspect.
        // file_put_contents('contents.zip', $contents);

        // This hash has been manually verified to be the correct zip file.
        // Make sure that there is a file at the root called root.txt
        // with contents of "1".
        $this->assertEquals('8894314657ee3cb70ac4d3bc6bea8a09', md5($contents));
    }

    /** @test */
    public function an_exclamation_excludes_is_ignored()
    {
        $package = Package::make()
            ->include([
                'include',
            ])
            ->exclude([
                '!exclude',
            ]);

        $this->assertCount(1, $package->getIncludedPaths());
        $this->assertStringContainsString('include', $package->getIncludedPaths()[0]);

        $this->assertCount(1, $package->getExcludedPaths());
        $this->assertStringContainsString('exclude', $package->getExcludedPaths()[0]);
    }

    /** @test */
    public function hashes_are_stable()
    {
        $package = $this->makePackageClass();

        $package->include([
            'Support/Files'
        ]);

        $this->assertEquals('f0832737d1b192e7d29719aa7303c2a9', $package->hash());
        $this->assertEquals('f0832737d1b192e7d29719aa7303c2a9', $package->hash());
    }

    /** @test */
    public function exact_includes_affect_hashes()
    {
        $package = $this->makePackageClass();

        $package->include([
            'Support/Files'
        ]);

        $this->assertEquals('f0832737d1b192e7d29719aa7303c2a9', $package->hash());

        $package->includeExactly([
            __DIR__ . '/Support/Files/file1.txt' => 'bar'
        ]);

        $this->assertEquals('5ba72e0b5627858527b685c374c98cda', $package->hash());

        $package->includeExactly([
            __DIR__ . '/Support/Files/file1.txt' => 'buz'
        ]);

        $this->assertEquals('d8cd3151e4557293ea0459d3b21827c9', $package->hash());
    }

    /** @test */
    public function hashes_change_based_on_file_content()
    {
        $package = $this->makePackageClass();

        $package->include([
            'Support/Files'
        ]);

        file_put_contents(__DIR__ . '/Support/Files/file3.txt', '');

        $this->assertEquals('f0832737d1b192e7d29719aa7303c2a9', $package->hash());

        file_put_contents(__DIR__ . '/Support/Files/file3.txt', 'Some new data');

        $this->assertEquals('0a1e2cc16698253a6fbb9938cfdeaf91', $package->hash());

        file_put_contents(__DIR__ . '/Support/Files/file3.txt', '');

        $this->assertEquals('f0832737d1b192e7d29719aa7303c2a9', $package->hash());
    }

    /** @test */
    public function it_writes_to_the_s3_stream()
    {
        $package = $this->makePackageClass();

        $package->include([
            'Support/Files'
        ]);

        $package->upload();

        $this->assertArrayHasKey('s3://sidecar-bucket/sidecar/001-f0832737d1b192e7d29719aa7303c2a9.zip', FakeStreamWrapper::$paths);

        $contents = FakeStreamWrapper::$paths['s3://sidecar-bucket/sidecar/001-f0832737d1b192e7d29719aa7303c2a9.zip'];

        // Write the contents to disk to inspect.
        // file_put_contents('contents.zip', $contents);

        // This hash has been manually verified to be the correct zip file.
        $this->assertEquals('d9826f2d35243727a4a5e3fe2e1d8ad4', md5($contents));
    }

    /** @test */
    public function if_file_already_exists_it_doesnt_make_a_new_one()
    {
        $package = $this->makePackageClass();
        $package->include([
            'Support/Files'
        ]);

        // Pretend it's already on S3
        FakeStreamWrapper::$paths = [
            's3://sidecar-bucket/sidecar/001-f0832737d1b192e7d29719aa7303c2a9.zip' => 'fake'
        ];

        $package->upload();

        // Only 1 call to S3, to see if it exists. No calls to write anything.
        $this->assertCount(1, FakeStreamWrapper::$calls);
        $this->assertEquals('url_stat', FakeStreamWrapper::$calls[0][0]);
    }

    /** @test */
    public function it_creates_the_correct_deployment_configuration()
    {
        $package = $this->makePackageClass();

        $package->include([
            'Support/Files'
        ]);

        $this->assertEquals([
            'S3Bucket' => 'sidecar-bucket',
            'S3Key' => 'sidecar/001-f0832737d1b192e7d29719aa7303c2a9.zip',
        ], $package->deploymentConfiguration());
    }
}
