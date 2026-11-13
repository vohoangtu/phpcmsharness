# Extension API

Extensions are trusted PHP packages loaded during application startup. Install a package through Composer, add its manifest to `config/extensions.php`, and implement `App\Application\Extension\ExtensionProviderInterface`.

Providers execute as application code and can access the configured services. Only add packages from sources you trust. The admin interface does not install arbitrary PHP packages. Implement `RoutableExtensionProviderInterface` only when a package adds routes; providers without routes can implement `ExtensionProviderInterface`.

## Provider contract

```php
<?php

namespace Acme\CatalogExtension;

use App\Application\Extension\ExtensionContext;
use App\Application\Extension\RoutableExtensionProviderInterface;
use App\Presentation\Http\Router;
use App\Presentation\View\ViewRenderer;

final class Provider implements RoutableExtensionProviderInterface
{
    public function register(ExtensionContext $context): void
    {
        $context->hooks->addAction('product.saved', function ($product): void {
            // Sync the saved product with a trusted external service.
        });

        $context->hooks->addFilter('frontend.meta', function (array $meta, string $template): array {
            return $meta;
        });

        $context->registerService('extension.acme.catalog.client', fn ($container) => new CatalogClient());
    }

    public function registerRoutes(Router $router, ViewRenderer $view): void
    {
        $router->get('/admin/catalog-sync', function () use ($view): void {
            $view->renderAdmin('admin/catalog-sync', ['pageTitle' => 'Catalog Sync']);
        }, null, true);
    }
}
```

Add the provider class to `config/extensions.php`:

```php
return [
    [
        'id' => 'acme.catalog',
        'name' => 'Acme Catalog Sync',
        'version' => '1.0.0',
        'description' => 'Đồng bộ sản phẩm với Acme Catalog.',
        'provider' => Acme\CatalogExtension\Provider::class,
    ],
];
```

Installed providers are listed at **Admin → Extensions**. Activation state is stored in system settings; disabled providers do not register hooks, services, or routes on the next request.

The provider namespace must be Composer-autoloadable. Extension service IDs must start with `extension.`. Use `adminAuth: true` for administrator routes; POST requests still pass through the normal CSRF check.

## Available hooks

| Hook | Type | Arguments / value |
| --- | --- | --- |
| `post.saved` | Action | Saved `Post` entity |
| `product.saved` | Action | Saved `Product` entity |
| `media.uploaded` | Action | Saved `MediaFile` entity |
| `order.created` | Action | Saved `Order` entity, after the order transaction commits |
| `order.status_changed` | Action | Updated `Order`, previous `OrderStatus`, new `OrderStatus`, actor |
| `frontend.meta` | Filter | Metadata array (`title`, `description`, `canonical_url`, `image`), template name, view data |
| `frontend.head` | Filter | HTML string inserted into the frontend `<head>`, template name, view data |
| `admin.nav.items` | Filter | List of `{href, label, icon}` entries; only local `/admin/` links are rendered |

Action and filter listeners run in ascending priority order (default `10`). A failing listener is logged and skipped so it cannot turn a saved order or content change into a failed request.
