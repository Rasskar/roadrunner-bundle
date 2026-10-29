# RoadRunner Bundle для Symfony

Бандл запускает Symfony-приложение под [RoadRunner](https://roadrunner.dev/) и даёт доступ
к KV-хранилищам RoadRunner (Redis, Memcached, BoltDB, in-memory) как к обычному кэшу Symfony.

- [Зачем это нужно](#зачем-это-нужно)
- [Что умеет бандл](#что-умеет-бандл)
- [Требования](#требования)
- [Установка](#установка)
- [Настройка проекта](#настройка-проекта)
- [HTTP](#http)
- [KV-кэш](#kv-кэш)
- [Правила долгоживущего процесса](#правила-долгоживущего-процесса)
- [Как это устроено внутри](#как-это-устроено-внутри)
- [Лицензия](#лицензия)

---

## Зачем это нужно

**Классическая схема (nginx + php-fpm).** На каждый запрос PHP заново загружает классы, собирает
контейнер, создаёт сервисы, обрабатывает запрос и всё выбрасывает. Следующий запрос начинает с нуля.

**RoadRunner.** Это сервер на Go, который держит несколько PHP-процессов (*воркеров*) постоянно
запущенными. Каждый воркер один раз загружает Symfony, а затем обрабатывает запросы один за другим
в цикле. Подготовительная работа выполняется один раз за жизнь процесса, а не на каждый запрос, поэтому
ответы заметно быстрее.

```
php-fpm:      запрос → [загрузка Symfony → обработка → выгрузка]   ← и так каждый раз
RoadRunner:   [загрузка Symfony] → запрос → запрос → запрос → ...  ← загрузка один раз
```

Бандл берёт на себя всю связку между RoadRunner и Symfony: принимает запросы от RoadRunner, прогоняет
их через ядро Symfony, отдаёт ответы и следит, чтобы данные одного запроса не протекали в следующий.

## Что умеет бандл

- **HTTP-воркер.** Ваше приложение работает под RoadRunner без изменений в контроллерах и коде.
- **Корректный `kernel.terminate`.** Слушатели `kernel.terminate` выполняются *после* того, как
  клиент получил ответ, как в php-fpm.
- **Сброс состояния между запросами.** Сервисы с `ResetInterface` (Doctrine, Monolog, сессии и т.д.)
  очищаются после каждого запроса.
- **Устойчивость Doctrine.** Протухшие соединения с базой закрываются перед запросом,
  а «сломанный» после ошибки EntityManager пересоздаётся. Включается само, если в проекте есть Doctrine.
- **Отдача больших файлов кусками.** `BinaryFileResponse` уходит клиенту частями по 8 КБ, а не
  загружается в память целиком.
- **PSR-15 middleware.** Можно добавить свою обработку запроса до и после Symfony.
- **События жизненного цикла воркера.** `WorkerStartEvent` и `WorkerStopEvent`.
- **KV-кэш.** Любое количество KV-хранилищ RoadRunner доступно как сервисы кэша Symfony.

## Требования

| Что | Версия |
|---|---|
| PHP | 8.4+ |
| Symfony | 7.x или 8.x |
| RoadRunner (бинарник) | 2025.x |

## Установка

### 1. Пакет

Добавьте репозиторий в `composer.json` вашего проекта:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/Rasskar/roadrunner-bundle" }
    ]
}
```

И установите:

```bash
composer require rasskar/roadrunner-bundle
```

### 2. Регистрация бандла

`config/bundles.php`:

```php
return [
    // ...
    Rasskar\RoadRunnerBundle\RoadRunnerBundle::class => ['all' => true],
];
```

### 3. Бинарник RoadRunner

RoadRunner — это отдельная программа, не PHP-пакет. Проще всего скачать её в корень проекта:

```bash
vendor/bin/rr get-binary
```

Появится файл `./rr`. Другие способы установки (Docker-образ, Homebrew и т.д.) описаны в
[документации RoadRunner](https://docs.roadrunner.dev/docs/general/install).

## Настройка проекта

### `.rr.yaml`

Создайте в корне проекта файл `.rr.yaml`. Минимальная рабочая конфигурация:

```yaml
version: "3"

server:
    command: "php public/index.php"
    relay: pipes
    env:
        - APP_RUNTIME: Rasskar\RoadRunnerBundle\Runtime\Runtime

http:
    address: 0.0.0.0:8080
    pool:
        num_workers: 4

rpc:
    listen: tcp://127.0.0.1:6001
```

Что здесь важно:

| Параметр | Зачем |
|---|---|
| `server.command` | Как запускать воркер. Используется **обычный** `public/index.php` Symfony, менять его не нужно. |
| `server.relay: pipes` | Как RoadRunner обменивается данными с PHP: через стандартные потоки ввода-вывода. Рекомендуемый вариант. |
| `server.env.APP_RUNTIME` | **Главная строка.** Подключает Runtime бандла: благодаря ей Symfony понимает, что работает под RoadRunner. |
| `http.pool.num_workers` | Сколько PHP-процессов держать запущенными. Это же число запросов, которые обрабатываются одновременно. |
| `rpc.listen` | Адрес, по которому PHP может обращаться к RoadRunner. Нужен для KV-кэша. |

> Runtime бандла можно подключить и для всего проекта через `composer.json`
> (`"extra": {"runtime": {"class": "Rasskar\\RoadRunnerBundle\\Runtime\\Runtime"}}` и
> `composer dump-autoload`). Это безопасно: вне RoadRunner (`bin/console`, php-fpm) он ведёт себя
> как стандартный Runtime Symfony.

### Логи — только в stderr

При `relay: pipes` стандартный вывод (stdout) — это канал, по которому PHP отдаёт ответы
RoadRunner. Если писать туда логи, протокол сломается. Направьте Monolog в `php://stderr`, и
RoadRunner покажет логи у себя в консоли:

```yaml
# config/packages/monolog.yaml
monolog:
    handlers:
        main:
            type: stream
            path: "php://stderr"
            level: debug
```

### Статика

Под RoadRunner нет nginx, который отдавал бы CSS, JS и картинки. Включите встроенный модуль статики:

```yaml
http:
    middleware: [ "static" ]
    static:
        dir: "public"
        forbid: [ ".php" ]
```

### Режим разработки

Воркер загружает код один раз, поэтому изменения в PHP-файлах сами не подхватятся. Для разработки
включите автоперезапуск воркеров при изменении файлов:

```yaml
reload:
    interval: 1s
    patterns: [ ".php", ".yaml" ]
    services:
        http:
            dirs: [ "src", "config" ]
            recursive: true
```

Для продакшена этот блок не нужен.

### Запуск

```bash
./rr serve
```

Приложение доступно на `http://localhost:8080`.

---

## HTTP

HTTP работает сразу после установки: дополнительной настройки бандла не требуется. Контроллеры,
роутинг, формы, security, Twig — всё работает как обычно.

### Что происходит с каждым запросом

1. RoadRunner передаёт запрос свободному воркеру.
2. Запрос проходит через цепочку middleware (если они есть).
3. Symfony обрабатывает запрос как обычно: роутинг → контроллер → `Response`.
4. Ответ отправляется клиенту.
5. Выполняются слушатели `kernel.terminate`. Клиент их уже не ждёт.
6. Сервисы сбрасываются (`kernel.reset`), и воркер готов к следующему запросу.

### Ошибки

- Исключение внутри Symfony (в контроллере, сервисе и т.д.) обрабатывается как обычно: клиент
  получает страницу ошибки, воркер продолжает работать.
- Ошибка в самом слушателе `kernel.terminate` попадает в лог, воркер продолжает работать.
- Критическая ошибка вне Symfony (например, сломанная middleware) завершает воркер. RoadRunner
  сам запустит вместо него новый.

### Doctrine

Если в проекте установлена Doctrine, бандл автоматически подключает защиту для долгоживущего
процесса. Ничего настраивать не нужно.

**Проблема.** В php-fpm соединение с базой живёт один запрос. В воркере оно живёт часами, и за
время простоя база может его тихо закрыть. Кроме того, если запрос упал с ошибкой базы, Doctrine
закрывает EntityManager, и без вмешательства все следующие запросы в этом воркере тоже упадут.

**Решение:**
- **перед запросом** каждое открытое соединение проверяется лёгким запросом к базе. Мёртвое
  закрывается, и Doctrine сама переподключится при первом обращении;
- **после запроса** закрытые EntityManager пересоздаются, в лог пишется предупреждение.

### Свои middleware

Middleware — это обработчик, который оборачивает запрос: может что-то сделать до Symfony, после
неё или вообще не пустить запрос дальше. Подходит для CORS, режима обслуживания, замера времени и
т.п. Используется стандарт PSR-15:

```php
namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class TimingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start = microtime(true);

        $response = $handler->handle($request);

        return $response->withHeader('X-Response-Time', round((microtime(true) - $start) * 1000) . 'ms');
    }
}
```

Зарегистрируйте её с тегом `rr.http_middleware`:

```yaml
# config/services.yaml
services:
    App\Http\Middleware\TimingMiddleware:
        tags:
            - { name: 'rr.http_middleware', priority: 10 }
```

**Порядок.** Чем выше `priority`, тем раньше middleware получает запрос (тем «снаружи» она в цепочке).
У встроенной Doctrine-middleware приоритет `0`. При одинаковом приоритете порядок соответствует
порядку регистрации.

> Middleware работает с PSR-7 запросом, а не с Symfony `Request`. Для логики, которой нужны
> Symfony-сущности (пользователь, сессия, маршрут), используйте обычные слушатели событий ядра.

### События воркера

| Событие | Когда | Поля |
|---|---|---|
| `Rasskar\RoadRunnerBundle\Http\Event\WorkerStartEvent` | один раз, перед первым запросом | `pid` |
| `Rasskar\RoadRunnerBundle\Http\Event\WorkerStopEvent` | один раз, после последнего запроса | `pid`, `dueToError` |

```php
namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Rasskar\RoadRunnerBundle\Http\Event\WorkerStartEvent;
use Rasskar\RoadRunnerBundle\Http\Event\WorkerStopEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class WorkerLifecycleListener
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    #[AsEventListener]
    public function onStart(WorkerStartEvent $event): void
    {
        $this->logger->info('Worker started', ['pid' => $event->pid]);
    }

    #[AsEventListener]
    public function onStop(WorkerStopEvent $event): void
    {
        $this->logger->info('Worker stopped', ['pid' => $event->pid, 'error' => $event->dueToError]);
    }
}
```

События срабатывают вне HTTP-запроса, поэтому текущего запроса, пользователя и сессии в них нет.

---

## KV-кэш

KV (key-value) — хранилище «ключ → значение». RoadRunner умеет работать с несколькими такими
хранилищами одновременно. Бандл превращает каждое из них в сервис кэша Symfony.

**Как это работает.** PHP не подключается к Redis сам. Каждое обращение к кэшу — это запрос к
RoadRunner через RPC (адрес из `rpc.listen`), а RoadRunner уже работает с самим хранилищем:

```
ваш код  →  сервис кэша  →  RPC  →  RoadRunner  →  Redis / Memcached / BoltDB / память
```

### Шаг 1. Объявите хранилища в `.rr.yaml`

Каждое хранилище получает **имя** (ключ в секции `kv`) и **драйвер**. Хранилищ может быть сколько
угодно, в том числе несколько на одном драйвере:

```yaml
kv:
    cache:                 # основной кэш приложения
        driver: redis
        config:
            addrs: [ "127.0.0.1:6379" ]

    sessions:              # отдельный Redis под другие данные
        driver: redis
        config:
            addrs: [ "10.0.0.5:6379" ]

    local:                 # память самого RoadRunner, без внешних сервисов
        driver: memory
        config: { }

rpc:
    listen: tcp://127.0.0.1:6001    # обязательно для KV
```

Доступные драйверы:

| Драйвер | Где хранит | Пример `config` |
|---|---|---|
| `redis` | Redis | `addrs: [ "127.0.0.1:6379" ]` |
| `memcached` | Memcached | `addr: [ "127.0.0.1:11211" ]` |
| `boltdb` | файл на диске | `file: "var/kv.db"` |
| `memory` | память процесса RoadRunner (пропадает при перезапуске) | `{ }` |

Полный список опций каждого драйвера есть в [документации RoadRunner](https://docs.roadrunner.dev/docs/key-value/overview-kv).

### Шаг 2. Включите их в бандле

Создайте `config/packages/road_runner.yaml` и перечислите имена хранилищ, которые нужны в
приложении. Имена должны **точно совпадать** с ключами из `.rr.yaml`:

```yaml
road_runner:
    kv:
        storages: [ 'cache', 'sessions', 'local' ]
```

Для каждого имени бандл создаст сервис `cache.adapter.roadrunner.<имя>`:

| Имя в конфиге | Сервис |
|---|---|
| `cache` | `cache.adapter.roadrunner.cache` |
| `sessions` | `cache.adapter.roadrunner.sessions` |
| `local` | `cache.adapter.roadrunner.local` |

### Шаг 3. Используйте

Сервис реализует стандартные интерфейсы кэша Symfony: `Symfony\Contracts\Cache\CacheInterface` и
PSR-6 (`Psr\Cache\CacheItemPoolInterface`).

**Через атрибут `#[Autowire]`** (самый простой способ):

```php
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class ExchangeRateProvider
{
    public function __construct(
        #[Autowire(service: 'cache.adapter.roadrunner.cache')]
        private CacheInterface $cache,
    ) {
    }

    public function getUsdRate(): float
    {
        return $this->cache->get('rate_usd', function (ItemInterface $item): float {
            $item->expiresAfter(600);

            return $this->fetchRateFromBank();
        });
    }
}
```

`get()` возвращает значение из кэша, а если его там нет, вызывает функцию, сохраняет результат на
600 секунд и возвращает его.

**Через `bind` в `services.yaml`.** Удобно, когда хранилище нужно во многих классах: аргумент с
нужным именем будет подставляться автоматически.

```yaml
# config/services.yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true
        bind:
            Symfony\Contracts\Cache\CacheInterface $appCache: '@cache.adapter.roadrunner.cache'
            Symfony\Contracts\Cache\CacheInterface $localCache: '@cache.adapter.roadrunner.local'
```

```php
final class SomeService
{
    public function __construct(
        private CacheInterface $appCache,
        private CacheInterface $localCache,
    ) {
    }
}
```

**Основные операции:**

```php
$value = $this->cache->get('key', fn (ItemInterface $item) => compute());

$this->cache->delete('key');

$item = $this->cache->getItem('key');
$item->set($value)->expiresAfter(3600);
$this->cache->save($item);

$exists = $this->cache->hasItem('key');
```

### Важно знать

- **`clear()` очищает всё хранилище целиком**, а не только ключи вашего приложения. Если
  хранилищем пользуется кто-то ещё, его данные тоже пропадут.
- **Значения хранятся через PHP `serialize()`.** Если кэш читают несколько приложений, храните в нём
  простые данные (строки, числа, массивы). Объект класса, которого нет в приложении-читателе, не
  восстановится.
- **Префикса у ключей нет.** Ключ `rate_usd` будет одним и тем же для всех, кто смотрит в это
  хранилище. Если данные нужно разделить, заведите отдельные хранилища в `.rr.yaml`.
- **В консоли (`bin/console`)** кэш тоже работает, если запущен RoadRunner: по умолчанию
  используется адрес `tcp://127.0.0.1:6001`. Если `rpc.listen` у вас другой, задайте его в
  переменной окружения `RR_RPC` (например, в `.env`).

---

## Правила долгоживущего процесса

Воркер живёт долго и обрабатывает тысячи запросов подряд. Большинство приложений работает без
изменений, но несколько вещей нужно учитывать.

| Нельзя | Почему | Что делать |
|---|---|---|
| Хранить данные запроса в свойствах сервисов или в `static` | Сервис один на все запросы, данные «перетекут» к следующему пользователю | Передавать данные через аргументы или реализовать `ResetInterface` |
| `dd()`, `exit()`, `die()` | Завершают процесс воркера | Использовать логгер или `dump()` |
| Писать в `php://stdout` | Это канал связи с RoadRunner, ответ сломается | Логи — в `php://stderr` |
| Копить данные в памяти без ограничений | Память процесса не освобождается между запросами | Ограничивать кэши в памяти, при необходимости задать `pool.max_jobs` |

**`ResetInterface`.** Если сервис хранит что-то, что нужно очищать между запросами, реализуйте
`Symfony\Contracts\Service\ResetInterface`. Бандл вызовет `reset()` после каждого запроса:

```php
use Symfony\Contracts\Service\ResetInterface;

final class RequestContextHolder implements ResetInterface
{
    private array $data = [];

    public function reset(): void
    {
        $this->data = [];
    }
}
```

При включённом `autoconfigure` (по умолчанию в Symfony) ничего регистрировать не нужно.

**Профилактический перезапуск.** RoadRunner может перезапускать воркер после N запросов. Это
страхует от постепенных утечек памяти в сторонних библиотеках:

```yaml
http:
    pool:
        num_workers: 4
        max_jobs: 1000
```

---

## Как это устроено внутри

### Путь запроса

```
RoadRunner (Go)
   │  запускает: php public/index.php
   ▼
Runtime\Runtime              задан RR_MODE → работаем под RoadRunner
   ▼
Runtime\Runner               загружает ядро Symfony один раз, выбирает воркер по RR_MODE
   ▼
Storages\WorkerStorage       ищет среди сервисов с тегом rr.worker тот, что поддерживает режим
   ▼
Workers\HttpWorker           цикл: ждать запрос → обработать → ответить → terminate → reset
   ▼
Http\PipelineFactory         цепочка PSR-15: middleware (rr.http_middleware) + обработчик Symfony
   ▼
Http\Middleware\DoctrineResilienceMiddleware      (если установлена Doctrine)
   ▼
Http\RequestHandler\SymfonyKernelRequestHandler   PSR-7 → Symfony Request → Kernel → Response → PSR-7
   ▼
Http\KernelTerminator        откладывает kernel.terminate до момента, когда ответ уже отправлен
```

### Карта файлов

| Файл | Что делает |
|---|---|
| `src/RoadRunnerBundle.php` | Класс бандла. |
| `src/DependencyInjection/RoadRunnerExtension.php` | Загружает сервисы бандла и применяет конфигурацию `road_runner:`. |
| `src/DependencyInjection/Configuration.php` | Описывает допустимую структуру `road_runner:` в yaml. |
| `src/DependencyInjection/Configurators/KvConfigurator.php` | Создаёт сервис `cache.adapter.roadrunner.<имя>` для каждого KV-хранилища. |
| `config/services.php` | Регистрация всех сервисов бандла. |
| `src/Runtime/Runtime.php` | Определяет, запущен ли процесс RoadRunner, и подменяет стандартный запуск Symfony. |
| `src/Runtime/Runner.php` | Загружает ядро и запускает воркер для текущего режима. |
| `src/Storages/WorkerStorage.php` | Выбирает воркер по режиму `RR_MODE`. |
| `src/Workers/Contracts/WorkerInterface.php` | Контракт воркера: `run()` и `supports()`. |
| `src/Workers/HttpWorker.php` | Цикл обработки HTTP-запросов. |
| `src/Http/PipelineFactory.php` | Собирает цепочку middleware. |
| `src/Http/RequestHandler/SymfonyKernelRequestHandler.php` | Конвертирует запрос в Symfony, вызывает ядро, конвертирует ответ обратно. |
| `src/Http/KernelTerminator.php` | Выполняет `kernel.terminate` после отправки ответа. |
| `src/Http/Middleware/DoctrineResilienceMiddleware.php` | Проверка соединений и восстановление EntityManager. |
| `src/Http/Event/*` | События запуска и остановки воркера. |
| `src/Kv/KvCacheAdapter.php` | Адаптер KV-хранилища RoadRunner к кэшу Symfony. |
| `src/Rpc/RPCFactory.php` | Создаёт RPC-соединение с RoadRunner, общее для всего процесса. |

### С чего начать чтение кода

1. **`Runtime/Runtime.php` → `Runtime/Runner.php`** — как процесс понимает, что он под RoadRunner,
   и что запускает.
2. **`Workers/HttpWorker.php`** — сердце бандла: весь жизненный цикл запроса в одном методе `run()`.
3. **`Http/RequestHandler/SymfonyKernelRequestHandler.php`** — где RoadRunner-запрос встречается с
   Symfony.
4. **`config/services.php`** — как всё это связано между собой.
5. **`DependencyInjection/*` → `Kv/KvCacheAdapter.php`** — как строчка в yaml превращается в
   сервис кэша.

### Используемые библиотеки

| Пакет | Роль |
|---|---|
| `symfony/runtime` | Механизм подмены способа запуска приложения (`APP_RUNTIME`). |
| `spiral/roadrunner-http` | Получение запросов от RoadRunner и отправка ответов. |
| `spiral/goridge` | Протокол обмена данными и RPC с RoadRunner. |
| `spiral/roadrunner-kv` | Клиент KV-хранилищ RoadRunner. |
| `symfony/psr-http-message-bridge` | Конвертация PSR-7 ↔ Symfony HttpFoundation. |
| `nyholm/psr7` | Реализация PSR-7 объектов. |
| `relay/relay` | Запуск цепочки PSR-15 middleware. |
| `symfony/cache` | `Psr16Adapter`, на котором построен KV-адаптер. |

---

## Лицензия

MIT