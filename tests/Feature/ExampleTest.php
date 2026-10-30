<?php

it('shows the placeholder home page', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('CorralBusiness – RMS')
        ->assertSee('Menu');
});
