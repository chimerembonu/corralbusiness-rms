<?php

it('shows the start screen with big buttons to every screen', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('CorralBusiness – RMS')
        ->assertSee('Till (POS)')->assertSee('Kitchen')->assertSee('Back office')->assertSee('Clock-in')
        ->assertSee(url('pos'))->assertSee(url('kitchen'))->assertSee(url('admin'))->assertSee(route('clock-in'))
        ->assertSee('Store server running');
});

it('shows the clock-in placeholder', function () {
    $this->get('/clock-in')->assertOk()->assertSee('Phase 2');
});
