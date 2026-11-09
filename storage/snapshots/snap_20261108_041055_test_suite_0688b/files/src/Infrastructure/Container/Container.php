<?php

declare(strict_types=1);

namespace App\Infrastructure\Container;

use App\Application\Service\AuthService;
use App\Application\Service\CommentService;
use App\Application\Service\MediaService;
use App\Application\Service\ModuleManagerService;
use App\Application\Service\OrderService;
use App\Application\Service\PostService;
use App\Application\Service\ProductService;
use App\Application\Service\TaxonomyService;
use App\Application\Service\TuzygentHarnessService;
use App\Domain\Port\Outbound\CartPort;
use App\Domain\Port\Outbound\CategoryRepositoryPort;
use App\Domain\Port\Outbound\CommentRepositoryPort;
use App\Domain\Port\Outbound\HarnessRepositoryPort;
use App\Domain\Port\Outbound\MediaRepositoryPort;
use App\Domain\Port\Outbound\MediaStoragePort;
use App\Domain\Port\Outbound\ModuleRegistryPort;
use App\Domain\Port\Outbound\OrderRepositoryPort;
use App\Domain\Port\Outbound\PostRepositoryPort;
use App\Domain\Port\Outbound\ProductRepositoryPort;
use App\Domain\Port\Outbound\SettingRepositoryPort;
use App\Domain\Port\Outbound\TagRepositoryPort;
use App\Domain\Port\Outbound\UserRepositoryPort;
use App\Infrastructure\Persistence\Sqlite\DatabaseManager;
use App\Infrastructure\Persistence\Sqlite\SqliteCategoryRepository;
use App\Infrastructure\Persistence\Sqlite\SqliteCommentRepository;
use App\Infrastructure\Persistence\Sqlite\SqliteHarnessRepository;
use App\Infrastructure\Persistence\Sqlite\SqliteMediaRepository;
use App\Infrastructure\Persistence\Sqlite\SqliteModuleRepository;
use App\Infrastructure\Persistence\Sqlite\SqliteOrderRepository;
use App\Infrastructure\Persistence\Sqlite\SqlitePostRepository;
use App\Infrastructure\Persistence\Sqlite\SqliteProductRepository;
use App\Infrastructure\Persistence\Sqlite\SqliteSettingRepository;
use App\Infrastructure\Persistence\Sqlite\SqliteTagRepository;
use App\Infrastructure\Persistence\Sqlite\SqliteUserRepository;
use App\Infrastructure\Session\PhpSessionCartAdapter;
use App\Infrastructure\Storage\LocalMediaStorageAdapter;
use PDO;
use RuntimeException;

/**
 * Hexagonal Architecture Dependency Injection Container
 * Kết nối Outbound Ports (Interfaces) với Driven Adapters (SQLite / LocalFS / Session)
 * và khởi tạo Application Services (Inbound Use Cases).
 */
final class Container
{
    /** @var array<string, callable(self): object> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $instances = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config
    ) {
        $this->registerBindings();
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            /** @var T */
            return $this->instances[$id];
        }

        if (!isset($this->factories[$id])) {
            throw new RuntimeException("Service or Port [{$id}] is not registered in Hexagonal Container.");
        }

        $instance = ($this->factories[$id])($this);
        $this->instances[$id] = $instance;

        /** @var T */
        return $instance;
    }

    private function registerBindings(): void
    {
        // Database Connection
        $this->factories[PDO::class] = fn(): PDO => (new DatabaseManager($this->config))->getConnection();

        // Outbound Ports -> Driven Adapters
        $this->factories[ModuleRegistryPort::class] = fn(self $c) => new SqliteModuleRepository($c->get(PDO::class));
        $this->factories[PostRepositoryPort::class] = fn(self $c) => new SqlitePostRepository($c->get(PDO::class));
        $this->factories[ProductRepositoryPort::class] = fn(self $c) => new SqliteProductRepository($c->get(PDO::class));
        $this->factories[CategoryRepositoryPort::class] = fn(self $c) => new SqliteCategoryRepository($c->get(PDO::class));
        $this->factories[TagRepositoryPort::class] = fn(self $c) => new SqliteTagRepository($c->get(PDO::class));
        $this->factories[CommentRepositoryPort::class] = fn(self $c) => new SqliteCommentRepository($c->get(PDO::class));
        $this->factories[OrderRepositoryPort::class] = fn(self $c) => new SqliteOrderRepository($c->get(PDO::class));
        $this->factories[MediaRepositoryPort::class] = fn(self $c) => new SqliteMediaRepository($c->get(PDO::class));
        $this->factories[UserRepositoryPort::class] = fn(self $c) => new SqliteUserRepository($c->get(PDO::class));
        $this->factories[SettingRepositoryPort::class] = fn(self $c) => new SqliteSettingRepository($c->get(PDO::class));
        $this->factories[HarnessRepositoryPort::class] = fn(self $c) => new SqliteHarnessRepository($c->get(PDO::class));

        $this->factories[MediaStoragePort::class] = fn() => new LocalMediaStorageAdapter(
            (string) ($this->config['uploads_path'] ?? dirname(__DIR__, 3) . '/public/uploads'),
            (string) ($this->config['uploads_url'] ?? '/uploads')
        );
        $this->factories[CartPort::class] = fn() => new PhpSessionCartAdapter();

        // Application Layer Use Cases / Services
        $this->factories[ModuleManagerService::class] = fn(self $c) => new ModuleManagerService(
            $c->get(ModuleRegistryPort::class)
        );
        $this->factories[PostService::class] = fn(self $c) => new PostService(
            $c->get(PostRepositoryPort::class),
            $c->get(TagRepositoryPort::class)
        );
        $this->factories[ProductService::class] = fn(self $c) => new ProductService(
            $c->get(ProductRepositoryPort::class),
            $c->get(TagRepositoryPort::class)
        );
        $this->factories[TaxonomyService::class] = fn(self $c) => new TaxonomyService(
            $c->get(CategoryRepositoryPort::class),
            $c->get(TagRepositoryPort::class)
        );
        $this->factories[OrderService::class] = fn(self $c) => new OrderService(
            $c->get(OrderRepositoryPort::class),
            $c->get(ProductRepositoryPort::class),
            $c->get(CartPort::class)
        );
        $this->factories[CommentService::class] = fn(self $c) => new CommentService(
            $c->get(CommentRepositoryPort::class)
        );
        $this->factories[MediaService::class] = fn(self $c) => new MediaService(
            $c->get(MediaRepositoryPort::class),
            $c->get(MediaStoragePort::class)
        );
        $this->factories[AuthService::class] = fn(self $c) => new AuthService(
            $c->get(UserRepositoryPort::class)
        );
        $this->factories[TuzygentHarnessService::class] = fn(self $c) => new TuzygentHarnessService(
            $c->get(HarnessRepositoryPort::class),
            (string) ($this->config['base_path'] ?? dirname(__DIR__, 3))
        );
    }
}
