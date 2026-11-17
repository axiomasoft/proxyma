<?php

declare(strict_types=1);

namespace Proxyma\Filament\Tests\Support;

use Livewire\Livewire;
use LogicException;
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Application\Policy\CoreSettings;
use Proxyma\Application\Policy\SettingRegistry;
use Proxyma\Contracts\Bindings\SubjectResolver;
use Proxyma\Domain\Panels\PanelSlot;
use Proxyma\Domain\Panels\SubjectResolverDefinition;
use Proxyma\Domain\Policy\PanelDefinition;
use Proxyma\Domain\Policy\PolicyScope;
use Proxyma\Domain\Policy\SettingDescriptor;
use Proxyma\Domain\Policy\SettingMerge;
use Proxyma\Domain\Policy\SettingType;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Filament\Pages\BindingsPage;

class EditorSubjects implements SubjectResolver
{
    public array $chains = [];

    public function resolve(SubjectRef $leaf): ?SubjectChain
    {
        if (app('db')->connection('pgsql')->transactionLevel() !== 0) {
            throw new LogicException('Subject I/O inside SQL transaction.');
        }

        return $this->chains[$leaf->key()] ?? null;
    }
}

trait WithBindingEditor
{
    protected function prepareBindings(): void
    {
        $this->prepareFilament();
        $this->catalog = $this->provision();
        $this->subjects = new EditorSubjects;
        $this->folder = new SubjectRef($this->tenant, 'folder', 'root');
        $this->document = new SubjectRef($this->tenant, 'document', '42');
        $this->subjects->chains[$this->folder->key()] = new SubjectChain($this->folder);
        $this->subjects->chains[$this->document->key()] = new SubjectChain($this->document, [$this->folder]);
        $settings = new SettingRegistry([...CoreSettings::descriptors(),
            new SettingDescriptor('private.value', SettingType::IdentifierList, null, true, [PolicyScope::Entity], SettingMerge::Replace, null, null, 'private.edit', sensitive: true),
            new SettingDescriptor('owner.only', SettingType::Boolean, false, false, [PolicyScope::Tenant], SettingMerge::Replace, null, null, 'owner.edit'),
        ]);
        $this->app->instance(SettingRegistry::class, $settings);
        $definitions = new DefinitionRegistry($settings);
        $definitions->registerSubjectResolver(new SubjectResolverDefinition('files', $this->subjects, ['folder', 'document']));
        foreach (['files', 'archive'] as $panel) {
            $definitions->registerPanel(new PanelDefinition($panel, 'files', [
                new PanelSlot('read', ['timeout.connect_ms', 'destinations.allowed', 'routes.denied', 'route.allow_direct', 'route.strategy', 'retry.delays_ms', 'private.value']),
                new PanelSlot('write', ['timeout.connect_ms']),
                new PanelSlot('explicit', ['timeout.connect_ms'], inheritDefaults: false),
            ]));
        }
        $definitions->freeze();
        $this->app->instance(DefinitionRegistry::class, $definitions);
    }

    protected function bindingPage(string $class = BindingsPage::class, string $panel = 'files', string $slot = 'read', ?SubjectRef $subject = null)
    {
        $subject ??= $this->document;

        return Livewire::test($class)->set('selection', ['panel' => $panel, 'slot' => $slot, 'type' => $subject->type,
            'id' => $subject->id, 'target' => $this->catalog['target']->value])->call('loadBinding')->assertHasNoErrors();
    }
}
