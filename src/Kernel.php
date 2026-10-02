<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        // Must run before DoctrineBundle's RegisterEventListenersAndSubscribersPass
        // (same type, priority 0, registered earlier), hence the higher priority.
        $container->addCompilerPass($this, PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
    }

    /**
     * DoctrineBundle tags the Messenger schema listener for both
     * postGenerateSchema and onSchemaCreateTable. The latter is deprecated in
     * DBAL 3 and a no-op on MySQL (it only acts on PostgreSQL), so drop just
     * that tag. postGenerateSchema stays: it keeps messenger_messages out of
     * migrations:diff. Remove this pass with the DBAL 4 upgrade.
     */
    public function process(ContainerBuilder $container): void
    {
        $id = 'doctrine.orm.messenger.doctrine_schema_listener';
        if (!$container->hasDefinition($id)) {
            return;
        }

        $definition = $container->getDefinition($id);
        $tags = $definition->getTags();
        $kept = array_values(array_filter(
            $tags['doctrine.event_listener'] ?? [],
            static fn (array $attributes): bool => 'onSchemaCreateTable' !== ($attributes['event'] ?? null),
        ));
        $definition->clearTag('doctrine.event_listener');
        foreach ($kept as $attributes) {
            $definition->addTag('doctrine.event_listener', $attributes);
        }
    }
}
