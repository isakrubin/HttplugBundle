<?php

namespace Http\HttplugBundle\Tests\Unit\DependencyInjection;

use Http\Client\HttpClient;
use Http\Client\Plugin\Vcr\Recorder\InMemoryRecorder;
use Http\HttplugBundle\Collector\PluginClientFactoryListener;
use Http\HttplugBundle\DependencyInjection\HttplugExtension;
use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractExtensionTestCase;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel;
use Http\Adapter\Guzzle6\Client;

/**
 * @author David Buchmann <mail@davidbu.ch>
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class HttplugExtensionTest extends AbstractExtensionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setParameter('kernel.debug', true);
    }

    protected function getContainerExtensions()
    {
        return [
            new HttplugExtension(),
        ];
    }

    public function testConstants(): void
    {
        self::assertSame('httplug.client', HttplugExtension::HTTPLUG_CLIENT_TAG);
    }

    public function testConfigLoadDefault(): void
    {
        $this->load();

        foreach (['client', 'message_factory', 'uri_factory', 'stream_factory'] as $type) {
            $this->assertContainerBuilderHasAlias("httplug.$type", "httplug.$type.default");
        }
    }

    public function testConfigLoadClass(): void
    {
        $this->load([
            'classes' => [
                'client' => Client::class,
            ],
        ]);

        $this->assertContainerBuilderHasService('httplug.client.default', Client::class);
    }

    public function testConfigLoadService(): void
    {
        $this->load([
            'main_alias' => [
                'client' => 'my_client_service',
                'message_factory' => 'my_message_factory_service',
                'uri_factory' => 'my_uri_factory_service',
                'stream_factory' => 'my_stream_factory_service',
            ],
        ]);

        foreach (['client', 'message_factory', 'uri_factory', 'stream_factory'] as $type) {
            $this->assertContainerBuilderHasAlias("httplug.$type", "my_{$type}_service");
        }
    }

    public function testClientPlugins(): void
    {
        $this->load([
            'clients' => [
                'acme' => [
                    'factory' => 'httplug.factory.curl',
                    'plugins' => [
                        [
                            'decoder' => [
                                'use_content_encoding' => false,
                            ],
                        ],
                        'httplug.plugin.redirect',
                        [
                            'add_host' => [
                                'host' => 'http://localhost:8000',
                            ],
                        ],
                        [
                            'content_type' => [
                                'skip_detection' => true,
                            ],
                        ],
                        [
                            'header_append' => [
                                'headers' => ['X-FOO' => 'bar'],
                            ],
                        ],
                        [
                            'header_defaults' => [
                                'headers' => ['X-FOO' => 'bar'],
                            ],
                        ],
                        [
                            'header_set' => [
                                'headers' => ['X-FOO' => 'bar'],
                            ],
                        ],
                        [
                            'header_remove' => [
                                'headers' => ['X-FOO'],
                            ],
                        ],
                        [
                            'query_defaults' => [
                                'parameters' => ['locale' => 'en'],
                            ],
                        ],
                        [
                            'authentication' => [
                                'my_basic' => [
                                    'type' => 'basic',
                                    'username' => 'foo',
                                    'password' => 'bar',
                                ],
                            ],
                        ],
                        [
                            'cache' => [
                                'cache_pool' => 'my_cache_pool',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $plugins = [
            'httplug.client.acme.plugin.decoder',
            'httplug.plugin.redirect',
            'httplug.client.acme.plugin.add_host',
            'httplug.client.acme.plugin.content_type',
            'httplug.client.acme.plugin.header_append',
            'httplug.client.acme.plugin.header_defaults',
            'httplug.client.acme.plugin.header_set',
            'httplug.client.acme.plugin.header_remove',
            'httplug.client.acme.plugin.query_defaults',
            'httplug.client.acme.authentication.my_basic',
            'httplug.client.acme.plugin.cache',
        ];
        $pluginReferences = array_map(function ($id) {
            return new Reference($id);
        }, $plugins);

        $this->assertContainerBuilderHasService('httplug.client.acme');
        foreach ($plugins as $id) {
            $this->assertContainerBuilderHasService($id);
        }
        $this->assertContainerBuilderHasServiceDefinitionWithArgument('httplug.client.acme', 1, $pluginReferences);
        $this->assertContainerBuilderHasService('httplug.client.mock');
    }

    /**
     * @group legacy
     */
    public function testNoProfilingWhenToolbarIsDisabled(): void
    {
        $this->load(
            [
                'toolbar' => [
                    'enabled' => false,
                ],
                'clients' => [
                    'acme' => [
                        'factory' => 'httplug.factory.curl',
                        'plugins' => ['foo'],
                    ],
                ],
            ]
        );

        $this->verifyProfilingDisabled();
    }

    public function testNoProfilingWhenNotInDebugMode(): void
    {
        $this->setParameter('kernel.debug', false);
        $this->load(
            [
                'clients' => [
                    'acme' => [
                        'factory' => 'httplug.factory.curl',
                        'plugins' => ['foo'],
                    ],
                ],
            ]
        );

        $this->verifyProfilingDisabled();
    }

    /**
     * @group legacy
     */
    public function testProfilingWhenToolbarIsSpecificallyOn(): void
    {
        $this->setParameter('kernel.debug', false);
        $this->load(
            [
                'toolbar' => [
                    'enabled' => true,
                ],
                'clients' => [
                    'acme' => [
                        'factory' => 'httplug.factory.curl',
                        'plugins' => ['foo'],
                    ],
                ],
            ]
        );

        $this->assertContainerBuilderHasService(PluginClientFactoryListener::class);
    }

    public function testOverrideProfilingFormatter(): void
    {
        $this->load(
            [
                'profiling' => [
                    'formatter' => 'acme.formatter',
                ],
            ]
        );

        $def = $this->container->findDefinition('httplug.collector.formatter');
        $this->assertEquals('acme.formatter', (string) $def->getArgument(0));
    }

    public function testCachePluginConfigCacheKeyGeneratorReference(): void
    {
        $this->load([
            'plugins' => [
                'cache' => [
                    'cache_pool' => 'my_cache_pool',
                    'config' => [
                        'cache_key_generator' => 'header_cache_key_generator',
                    ],
                ],
            ],
        ]);

        $cachePlugin = $this->container->findDefinition('httplug.plugin.cache');

        $config = $cachePlugin->getArgument(2);
        $this->assertArrayHasKey('cache_key_generator', $config);
        $this->assertInstanceOf(Reference::class, $config['cache_key_generator']);
        $this->assertSame('header_cache_key_generator', (string) $config['cache_key_generator']);
    }

    public function testContentTypePluginAllowedOptions(): void
    {
        $this->load([
            'clients' => [
                'acme' => [
                    'plugins' => [
                        [
                            'content_type' => [
                                'skip_detection' => true,
                                'size_limit' => 200000,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $cachePlugin = $this->container->findDefinition('httplug.client.acme.plugin.content_type');

        $config = $cachePlugin->getArgument(0);
        $this->assertEquals([
            'skip_detection' => true,
            'size_limit' => 200000,
        ], $config);
    }

    public function testUsingServiceKeyForClients(): void
    {
        $this->load([
            'clients' => [
                'acme' => [
                    'service' => 'my_custom_client',
                ],
            ],
        ]);

        $client = $this->container->getAlias('httplug.client.acme.client');
        $this->assertEquals('my_custom_client', (string) $client);
        $this->assertFalse($client->isPublic());
    }

    private function verifyProfilingDisabled(): void
    {
        $def = $this->container->findDefinition('httplug.client');
        $this->assertTrue(is_subclass_of($def->getClass(), HttpClient::class));
        $arguments = $def->getArguments();

        if (isset($arguments[3])) {
            $this->assertEmpty(
                $arguments[3],
                'Parameter 3 to the PluginClient must not contain any debug_plugin information when profiling is disabled'
            );
        }
    }

    public function testClientShouldHaveDefaultVisibility(): void
    {
        $this->load([
            'clients' => [
                'acme' => [],
            ],
        ]);

        $this->assertContainerBuilderHasService('httplug.client.acme');

        if (version_compare(Kernel::VERSION, '3.4', '>=')) {
            // Symfony made services private by default starting from 3.4
            $this->assertTrue($this->container->getDefinition('httplug.client.acme')->isPublic());
            $this->assertTrue($this->container->getDefinition('httplug.client.acme')->isPrivate());
        } else {
            // Legacy Symfony
            $this->assertTrue($this->container->getDefinition('httplug.client.acme')->isPublic());
        }
    }

    public function testFlexibleClientShouldBePrivateByDefault(): void
    {
        $this->load([
            'clients' => [
                'acme' => [
                    'flexible_client' => true,
                ],
            ],
        ]);

        $this->assertContainerBuilderHasService('httplug.client.acme');
        $this->assertFalse($this->container->getDefinition('httplug.client.acme.flexible')->isPublic());
    }

    public function testHttpMethodsClientShouldBePrivateByDefault(): void
    {
        $this->load([
            'clients' => [
                'acme' => [
                    'http_methods_client' => true,
                ],
            ],
        ]);

        $this->assertContainerBuilderHasService('httplug.client.acme');
        $this->assertFalse($this->container->getDefinition('httplug.client.acme.http_methods')->isPublic());
    }

    public function testBatchClientShouldBePrivateByDefault(): void
    {
        $this->load([
            'clients' => [
                'acme' => [
                    'batch_client' => true,
                ],
            ],
        ]);

        $this->assertContainerBuilderHasService('httplug.client.acme');
        $this->assertFalse($this->container->getDefinition('httplug.client.acme.batch_client')->isPublic());
    }

    public function testClientCanBePublic(): void
    {
        $this->load([
            'clients' => [
                'acme' => [
                    'public' => true,
                ],
            ],
        ]);

        $this->assertContainerBuilderHasService('httplug.client.acme');
        $this->assertTrue($this->container->getDefinition('httplug.client.acme')->isPublic());

        if (version_compare(Kernel::VERSION, '3.4', '>=')) {
            // Symfony made services private by default starting from 3.4
            $this->assertFalse($this->container->getDefinition('httplug.client.acme')->isPrivate());
        }
    }

    public function testFlexibleClientCanBePublic(): void
    {
        $this->load([
            'clients' => [
                'acme' => [
                    'public' => true,
                    'flexible_client' => true,
                ],
            ],
        ]);

        $this->assertContainerBuilderHasService('httplug.client.acme');
        $this->assertTrue($this->container->getDefinition('httplug.client.acme.flexible')->isPublic());

        if (version_compare(Kernel::VERSION, '3.4', '>=')) {
            // Symfony made services private by default starting from 3.4
            $this->assertFalse($this->container->getDefinition('httplug.client.acme.flexible')->isPrivate());
        }
    }

    public function testHttpMethodsClientCanBePublic(): void
    {
        $this->load([
            'clients' => [
                'acme' => [
                    'public' => true,
                    'http_methods_client' => true,
                ],
            ],
        ]);

        $this->assertContainerBuilderHasService('httplug.client.acme');
        $this->assertTrue($this->container->getDefinition('httplug.client.acme.http_methods')->isPublic());

        if (version_compare(Kernel::VERSION, '3.4', '>=')) {
            // Symfony made services private by default starting from 3.4
            $this->assertFalse($this->container->getDefinition('httplug.client.acme.http_methods')->isPrivate());
        }
    }

    public function testBatchClientCanBePublic(): void
    {
        $this->load([
            'clients' => [
                'acme' => [
                    'public' => true,
                    'batch_client' => true,
                ],
            ],
        ]);

        $this->assertContainerBuilderHasService('httplug.client.acme');
        $this->assertTrue($this->container->getDefinition('httplug.client.acme.batch_client')->isPublic());

        if (version_compare(Kernel::VERSION, '3.4', '>=')) {
            // Symfony made services private by default starting from 3.4
            $this->assertFalse($this->container->getDefinition('httplug.client.acme.batch_client')->isPrivate());
        }
    }

    public function testClientIsTaggedWithHttplugClientTag(): void
    {
        $this->load([
            'clients' => [
                'acme' => null,
            ],
        ]);

        $serviceId = 'httplug.client.acme';

        $this->assertContainerBuilderHasService($serviceId);

        $this->assertTrue($this->container->getDefinition($serviceId)->hasTag(HttplugExtension::HTTPLUG_CLIENT_TAG), sprintf(
            'Failed asserting that client with service identifier "%s" has been tagged with "%s".',
            $serviceId,
            HttplugExtension::HTTPLUG_CLIENT_TAG
        ));
    }

    /**
     * @dataProvider provideVcrPluginConfig
     * @group vcr-plugin
     */
    public function testVcrPluginConfiguration(array $config, array $services, array $arguments = []): void
    {
        if (!class_exists(InMemoryRecorder::class)) {
            $this->markTestSkipped('VCR plugin is not installed.');
        }

        $prefix = 'httplug.client.acme.vcr';
        $this->load(['clients' => ['acme' => ['plugins' => [['vcr' => $config]]]]]);
        $this->assertContainerBuilderHasService('httplug.plugin.vcr.recorder.in_memory', InMemoryRecorder::class);

        foreach ($services as $service) {
            $this->assertContainerBuilderHasService($prefix.'.'.$service);
        }

        foreach ($arguments as $id => $args) {
            foreach ($args as $index => $value) {
                $this->assertContainerBuilderHasServiceDefinitionWithArgument($prefix.'.'.$id, $index, $value);
            }
        }
    }

    /**
     * @group vcr-plugin
     */
    public function testIsNotLoadedUnlessNeeded(): void
    {
        if (!class_exists(InMemoryRecorder::class)) {
            $this->markTestSkipped('VCR plugin is not installed.');
        }

        $this->load(['clients' => ['acme' => ['plugins' => []]]]);
        $this->assertContainerBuilderNotHasService('httplug.plugin.vcr.recorder.in_memory');
    }

    public function provideVcrPluginConfig()
    {
        $config = [
            'mode' => 'record',
            'recorder' => 'in_memory',
            'naming_strategy' => 'app.naming_strategy',
        ];
        yield [$config, ['record']];

        $config['mode'] = 'replay';
        yield [$config, ['replay']];

        $config['mode'] = 'replay_or_record';
        yield [$config, ['replay', 'record']];

        $config['recorder'] = 'filesystem';
        $config['fixtures_directory'] = __DIR__;
        unset($config['naming_strategy']);

        yield [$config, ['replay', 'record', 'recorder', 'naming_strategy'], ['replay' => [2 => false]]];

        $config['naming_strategy_options'] = [
            'hash_headers' => ['X-FOO'],
            'hash_body_methods' => ['PATCH'],
        ];

        yield [
            $config,
            ['replay', 'record', 'recorder', 'naming_strategy'],
            ['naming_strategy' => [$config['naming_strategy_options']]],
        ];
    }
}
