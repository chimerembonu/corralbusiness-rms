<?php

it('shows the start screen with big buttons to every screen', function () {
    provisionTenant(); // a set-up server (a fresh one opens the setup wizard instead)

    $this->get('/')
        ->assertOk()
        ->assertSee('CorralBusiness – RMS')
        ->assertSee('Till (POS)')->assertSee('Kitchen')->assertSee('Back office')->assertSee('Clock-in')
        ->assertSee(url('pos'))->assertSee(url('kitchen'))->assertSee(url('admin'))->assertSee(route('staff.clock'))
        ->assertSee('Store server running');
});

it('sends the old clock-in address to the clock screen', function () {
    $this->get('/clock-in')->assertRedirect('/clock');
});

it('prints the realtime settings into every page for the browser', function () {
    config()->set('rms.realtime', ['key' => 'abc123', 'host' => '', 'port' => '', 'scheme' => '', 'path' => '/reverb']);
    provisionTenant();

    $this->get('/')->assertOk()->assertSee('name="rms-realtime"', false)->assertSee('&quot;key&quot;:&quot;abc123&quot;', false)->assertSee('&quot;path&quot;:&quot;\/reverb&quot;', false)->assertDontSee('&quot;host&quot;', false);
});
