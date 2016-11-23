<?php

namespace E2E;

class KoelTest extends TestCase
{
    public function testDefaults()
    {
        static::assertContains('Koel', $this->driver->getTitle());

        $formSelector = '#app > div.login-wrapper > form';

        // Our login form should be there.
        static::assertCount(1, $this->els($formSelector));

        // We submit rubbish and expect an error class on the form.
        $this->login('foo@bar.com', 'ThisIsWongOnSoManyLevels')
            ->waitUntilSeen("$formSelector.error");

        // Now we submit good stuff and make sure we're in.
        $this->login()
            ->waitUntilTextSeenIn('Koel Admin', '#userBadge > a.view-profile.control > span');

        // Default URL must be Home
        static::assertEquals($this->url.'/#!/home', $this->driver->getCurrentURL());

        // While we're at this, test logging out as well.
        $this->click('#userBadge > a.logout');
        $this->waitUntilSeen($formSelector);
    }
}
