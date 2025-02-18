<?php
/**
 * This file is part of the LuneticsLocaleBundle package.
 *
 * <https://github.com/lunetics/LocaleBundle/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that is distributed with this source code.
 */

namespace Lunetics\LocaleBundle\Tests\EventListener;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

use Lunetics\LocaleBundle\EventListener\LocaleListener;
use Lunetics\LocaleBundle\LocaleBundleEvents;
use Lunetics\LocaleBundle\LocaleGuesser\LocaleGuesserManager;
use Lunetics\LocaleBundle\LocaleGuesser\RouterLocaleGuesser;
use Lunetics\LocaleBundle\LocaleGuesser\BrowserLocaleGuesser;
use Lunetics\LocaleBundle\LocaleGuesser\CookieLocaleGuesser;
use Lunetics\LocaleBundle\LocaleGuesser\QueryLocaleGuesser;
use Lunetics\LocaleBundle\LocaleInformation\AllowedLocalesProvider;
use Lunetics\LocaleBundle\Matcher\DefaultBestLocaleMatcher;
use Lunetics\LocaleBundle\Validator\MetaValidator;

class LocaleListenerTest extends TestCase
{
    public function testDefaultLocaleWithoutParams()
    {
        $listener = $this->getListener('fr', $this->getGuesserManager());
        $request = Request::create('/');
        $request->headers->set('Accept-language', '');
        $event = $this->getEvent($request);

        $listener->onKernelRequest($event);
        $this->assertEquals('fr', $request->getLocale());
    }

    public function getTestDataForBestLocaleMatcher()
    {
        return array(
            array('fr', array('fr'), 'fr', 'en'),
            array('fr_FR', array('fr'), 'fr', 'en'),
            array('fr_FR', array('fr_FR'), 'fr_FR', 'en'),
            array('fr_FR', array('en_GB'), 'en', 'en'),
        );
    }
    /**
     * @dataProvider getTestDataForBestLocaleMatcher
     */
    public function testAllowedLocaleWithMatcher($browserLocale, $allowedlocales, $expectedLocale, $fallback)
    {
        $listener = $this->getListener($fallback, $this->getGuesserManager(), null, $this->getBestLocaleMatcher($allowedlocales));
        $request = Request::create('/');
        $request->headers->set('Accept-language', $browserLocale);
        $event = $this->getEvent($request);

        $listener->onKernelRequest($event);
        $this->assertEquals($expectedLocale, $request->getLocale());
    }

    public function testCustomLocaleIsSetWhenParamsExist()
    {
        $listener = $this->getListener('fr', $this->getGuesserManager());
        $request = Request::create('/', 'GET');
        $request->attributes->set('_locale', 'de');
        $event = $this->getEvent($request);

        $listener->onKernelRequest($event);
        $this->assertEquals('de', $request->getLocale());
        $this->assertEquals('de', $request->attributes->get('_locale'));
    }

    public function testCustomLocaleIsSetWhenQueryExist()
    {
        $listener = $this->getListener('fr', $this->getGuesserManager(array(0 => 'router', 1 => 'query', 2 => 'browser')));
        $request = Request::create('/', 'GET');
        $request->query->set('_locale', 'de');
        $event = $this->getEvent($request);

        $listener->onKernelRequest($event);
        $this->assertEquals('de', $request->getLocale());
        $this->assertEquals('de', $request->attributes->get('_locale'));
    }

    /**
     * Router is prio 1
     * Request contains _locale parameter in router
     * Request contains browser locale preferences
     */
    public function testRouteLocaleIsReturnedIfRouterIsPrio1()
    {
        $request = $this->getFullRequest();
        $manager = $this->getGuesserManager();
        $listener = $this->getListener('en', $manager);
        $event = $this->getEvent($request);
        $listener->onKernelRequest($event);
        $this->assertEquals('es', $request->getLocale());
        $this->assertEquals('es', $request->attributes->get('_locale'));
    }

    /**
     * Browser is prio 1
     * Request contains _locale parameter in router
     * Request contains browser locale preferences
     */
    public function testBrowserLocaleIsReturnedIfBrowserIsPrio1()
    {
        $request = $this->getFullRequest();
        $manager = $this->getGuesserManager(array(1 => 'browser', 2 => 'router'));
        $listener = $this->getListener('en', $manager);
        $event = $this->getEvent($request);
        $listener->onKernelRequest($event);
        $this->assertEquals('fr_FR', $request->getLocale());
        $this->assertEquals('fr_FR', $request->attributes->get('_locale'));
    }

    /**
     * Router is prio 1
     * Request DOES NOT contains _locale parameter in router
     * Request contains browser locale preferences
     */
    public function testBrowserTakeOverIfRouterParamsFail()
    {
        $request = $this->getFullRequest(null);
        $manager = $this->getGuesserManager();
        $listener = $this->getListener('en', $manager);
        $event = $this->getEvent($request);
        $listener->onKernelRequest($event);
        $this->assertEquals('fr_FR', $request->getLocale());
        $this->assertEquals('fr_FR', $request->attributes->get('_locale'));
    }

    public function testThatGuesserIsNotCalledIfNotInGuessingOrder()
    {
        $request = $this->getRequestWithRouterParam();
        $manager = $this->getGuesserManager(array(0 => 'browser'));
        $listener = $this->getListener('en', $manager);
        $event = $this->getEvent($request);
        $listener->onKernelRequest($event);
        $this->assertEquals('en', $request->getLocale());
    }

    public function testDispatcherIsFired()
    {
        $dispatcherMock = $this->createMock(EventDispatcher::class);
        $dispatcherMock->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf('Lunetics\LocaleBundle\Event\FilterLocaleSwitchEvent'), $this->equalTo(LocaleBundleEvents::onLocaleChange));

        $listener = $this->getListener('fr', $this->getGuesserManager());
        $listener->setEventDispatcher($dispatcherMock);


        $event = $this->getEvent($this->getRequestWithRouterParam());
        $listener->onKernelRequest($event);
    }

    public function testDispatcherIsNotFired()
    {
        $dispatcherMock = $this->createMock(EventDispatcher::class);
        $dispatcherMock->expects($this->never())
            ->method('dispatch');

        $manager = $this->getGuesserManager();
        $manager->removeGuesser('session');
        $manager->removeGuesser('cookie');
        $listener = $this->getListener('fr', $manager);
        $listener->setEventDispatcher($dispatcherMock);

        $event = $this->getEvent($this->getRequestWithRouterParam());
        $listener->onKernelRequest($event);
    }

    /**
     * Request with empty route params and empty browser preferences
     */
    public function testDefaultLocaleIfEmptyRequest()
    {
        $request = $this->getEmptyRequest();
        $manager = $this->getGuesserManager();
        $listener = $this->getListener('en', $manager);
        $event = $this->getEvent($request);
        $listener->onKernelRequest($event);
        $this->assertEquals('en', $request->getLocale());
    }

    public function testAjaxRequestsAreHandled()
    {
        $request = $this->getRequestWithRouterParam('fr');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $manager = $this->getGuesserManager(array(0 => 'router'));
        $listener = $this->getListener('en', $manager);
        $event = $this->getEvent($request);
        $listener->onKernelRequest($event);
        $this->assertEquals('fr', $request->getLocale());
    }

    public function testOnLocaleDetectedSetVaryHeader()
    {
        $listener = $this->getListener();

        $response = $this->getMockResponse();
        $response
            ->expects($this->once())
            ->method('setVary')
            ->with('Accept-Language')
            ->will($this->returnValue($response));
        ;

        $ResponseEvent = $this->getMockResponseEvent();
        $ResponseEvent
            ->expects($this->once())
            ->method('getResponse')
            ->will($this->returnValue($response))
        ;

        $listener->onLocaleDetectedSetVaryHeader($ResponseEvent);
    }

    public function testOnLocaleDetectedDisabledVaryHeader()
    {
        $listener = $this->getListener();
        $listener->setDisableVaryHeader(true);

        $response = $this->getMockResponse();
        $response
            ->expects($this->never())
            ->method('setVary');
        $ResponseEvent = $this->getMockResponseEvent();
        $ResponseEvent
            ->expects($this->any())
            ->method('getResponse')
            ->will($this->returnValue($response));

        $listener->onLocaleDetectedSetVaryHeader($ResponseEvent);
    }

    public function excludedPatternDataProvider()
    {
        return array(
            array(null,    true),
            array('.*',    false),
            array('/api$', true),
            array('^/api', false),
        );
    }

    /**
     * @dataProvider excludedPatternDataProvider
     */
    public function testRunLocaleGuessingIsNotFiredIfPatternMatches ($pattern, $called)
    {
        $request = new Request(array(), array(), array(), array(), array(), array('REQUEST_URI' => '/api/users'));

        $guesserManager = $this->getMockGuesserManager();
        $guesserManager
            ->expects($this->exactly((int) $called))
            ->method('runLocaleGuessing');

        $listener = $this->getListener('en', $guesserManager);
        $listener->setExcludedPattern($pattern);

        $event = $this->getEvent($request);

        $listener->onKernelRequest($event);
    }

    public function testLogEvent()
    {
        $message = 'Setting [ 1 ] as locale for the (Sub-)Request';

        $request = $this->getEmptyRequest();

        $guesserManager = $this->getMockGuesserManager();
        $guesserManager
            ->expects($this->once())
            ->method('runLocaleGuessing')
            ->with($request)
            ->will($this->returnValue(true));


        $logger = $this->getMockLogger();
        $logger
            ->expects($this->once())
            ->method('info')
            ->with($message, array());

        $listener = $this->getListener('en', $guesserManager, $logger);

        $event = $this->getEvent($request);

        $listener->onKernelRequest($event);
    }

    public function testGetSubscribedEvents()
    {
        $subscribedEvents = LocaleListener::getSubscribedEvents();

        $this->assertEquals(array(array('onKernelRequest', 24)), $subscribedEvents[KernelEvents::REQUEST]);
        $this->assertEquals(array('onLocaleDetectedSetVaryHeader'), $subscribedEvents[KernelEvents::RESPONSE]);
    }

    private function getEvent(Request $request)
    {
        return new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MASTER_REQUEST);
    }

    private function getListener($locale = 'en', $manager = null, $logger = null, $matcher = null)
    {
        if (null === $manager) {
            $manager = $this->getGuesserManager();
        }

        $listener = new LocaleListener($locale, $manager, $matcher, $logger);
        $listener->setEventDispatcher(new EventDispatcher());

        return $listener;
    }

    private function getBestLocaleMatcher(array $allowedLocales)
    {
        return new DefaultBestLocaleMatcher(new AllowedLocalesProvider($allowedLocales));
    }

    private function getGuesserManager($order = array(1 => 'router', 2 => 'browser'))
    {
        $allowedLocales = array('de', 'fr', 'fr_FR', 'nl', 'es', 'en');
        $metaValidator = $this->getMetaValidatorMock();
        $callBack = function ($v) use ($allowedLocales) {
            return in_array($v, $allowedLocales);
        };
        $metaValidator->expects($this->any())
            ->method('isAllowed')
            ->will($this->returnCallback($callBack));

        $manager = new LocaleGuesserManager($order);
        $routerGuesser = new RouterLocaleGuesser($metaValidator);
        $browserGuesser = new BrowserLocaleGuesser($metaValidator);
        $cookieGuesser = new CookieLocaleGuesser($metaValidator, 'lunetics_locale');
        $queryGuesser = new QueryLocaleGuesser($metaValidator, '_locale');
        $manager->addGuesser($queryGuesser, 'query');
        $manager->addGuesser($routerGuesser, 'router');
        $manager->addGuesser($browserGuesser, 'browser');
        $manager->addGuesser($cookieGuesser, 'cookie');

        return $manager;
    }

    private function getMockGuesserManager()
    {
        return $this->createMock(LocaleGuesserManager::class);
    }

    /**
     * @return MetaValidator
     */
    private function getMetaValidatorMock()
    {
        return $this->createMock(MetaValidator::class);
    }

    private function getRequestWithRouterParam($routerLocale = 'es')
    {
        $request = Request::create('/');
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);
        if (!empty($routerLocale)) {
            $request->attributes->set('_locale', $routerLocale);
        }
        $request->headers->set('Accept-language', '');

        return $request;
    }

    private function getFullRequest($routerLocale = 'es')
    {
        $request = Request::create('/');
        if (!empty($routerLocale)) {
            $request->attributes->set('_locale', $routerLocale);
        }
        $request->headers->set('Accept-language', 'fr-FR,fr;q=0.8,en-US;q=0.6,en;q=0.4');

        return $request;
    }

    private function getEmptyRequest()
    {
        $request = Request::create('/');
        $request->headers->set('Accept-language', '');

        return $request;
    }

    private function getMockResponse()
    {
        return $this->createMock(Response::class);
    }

    private function getMockResponseEvent()
    {
        return $this->createMock(ResponseEvent::class);
    }

    private function getMockLogger()
    {
        return $this->createMock(LoggerInterface::class);
    }
}
