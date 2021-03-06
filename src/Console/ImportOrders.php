<?php

namespace Dan\Shopify\Laravel\Console;

use Carbon\Carbon;
use Dan\Shopify\Laravel\Jobs\Orders\ImportStore;
use Dan\Shopify\Laravel\Models\Store;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Log;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class ImportOrders
 */
class ImportOrders extends AbstractCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string $signature
     */
    protected $signature = 'shopify:import:orders {--dryrun} {--now} {--connection=sync} {--created_at_min=} {--limit=} {--store_ids=any} {--last_order_import_at_max=now}';

    /**
     * The console command description.
     *
     * @var string $description
     */
    protected $description = 'Verify and sync orders.';

    /** @var int $chunk_size */
    protected static $chunk_size = 100;

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        if (config('shopify.sync.enabled', true) != true) {
            $this->error('Sync has been disabled. Please re-enable in your `shopify` configuration.');
            return;
        }

        $params = [
            'created_at_min' => $this->option('created_at_min'),
            'limit' => $this->option('limit')
        ];

        $this->logCount();

        $this->getQuery()
            ->chunk(static::$chunk_size, function($stores) use ($params) {
                /** @var Store $store */
                foreach ($stores as $store) {
                    $this->handleStore($store, $params);
                }
            });
    }

    /**
     * @return Builder
     */
    protected function getQuery()
    {
        $last_order_import_at_max = new Carbon($this->option('last_order_import_at_max'));

        $store_model = config('shopify.stores.model', Store::class);
        /** @var \Dan\Shopify\Laravel\Models\Store $store_model */
        $store_model = new $store_model;
        $table = $store_model->getTable();

        return (new $store_model)
            ->newQuery()
            ->select('stores.*')
            ->forInstalled()
            ->where(function (Builder $query) use ($last_order_import_at_max, $table) {
                $query
                    ->where(function(Builder $q2) use ($table) {
                        $q2->whereNull("{$table}.last_order_import_at")
                            ->whereNotNull("{$table}.last_product_import_at");
                    })
                    ->orWhere("{$table}.last_product_import_at", '>', "{$table}.last_order_import_at");
            })
            ->when($this->optionIds('store_ids'), function(Builder $query, $store_ids) use($table) {
                $query->whereIn("{$table}.id", $store_ids);
            });
    }

    /**
     * @param Store $store
     * @param array $params
     * @throws Exception
     */
    protected function handleStore($store, $params)
    {
        $dryrun = $this->option('dryrun');
        $connection = $this->option('connection');

        if (empty(config("queue.connections.{$connection}"))) {
            $this->throwConnectionException();
        }

        $this->log($store);

        $job = new ImportStore($store, $params, $connection, $dryrun);

        $connection == 'sync'
            ? dispatch_now($job)
            : dispatch($job)->onConnection($connection);
    }

    /**
     * @param $store
     */
    protected function log($store): void
    {
        $verb = $this->option('now') ? 'dispatched' : 'queued';
        $msg = "cmd:shopify:orders:sync:{$store->myshopify_domain}:$verb";
        Log::channel(config('shopify.sync.log_channel'))
            ->info($msg, $store->compact());

        if ($this->verbosity % OutputInterface::VERBOSITY_VERBOSE == 0) {
            $this->info($msg);
        }
    }

    /**
     * @throws Exception
     */
    protected function throwConnectionException(): void
    {
        $valid_connections = array_keys(config('queue.connections'));
        $last_valid = array_pop($valid_connections);
        $message = "The queue connection \"{$this->option('connection')}\" is not valid. Use: "
            . implode(', ', $valid_connections) . "or {$last_valid}";

        throw new Exception($message);
    }

    protected function logCount(): void
    {
        $this->verbosity = $this->getOutput()->getVerbosity();

        if ($this->verbosity % OutputInterface::VERBOSITY_VERBOSE == 0) {
            $count = $this->getQuery()->count();
            $count
                ? $this->info("{$count} stores found. Syncing...")
                : $this->info('There are no stores that need syncing.');
        }
    }
}
