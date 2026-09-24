<?php

declare(strict_types=1);

namespace App\Tests;

use App\Controller\ReservationSearchController;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

use function array_filter;
use function array_values;
use function is_string;
use function json_decode;
use function str_contains;

abstract class ApiTestCase extends WebTestCase
{
    /**
     * @return array<mixed>
     */
    protected function getResponseBody(KernelBrowser $client): array
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        $body = json_decode($content, true);
        self::assertIsArray($body);

        return $body;
    }

    private function getRouter(): RouterInterface
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        return $router;
    }

    /**
     * An empty result means the controller is not routed; a broken lookup fails before returning.
     *
     * @return array<int, string>
     */
    protected function findRoutedControllersMatching(string $controllerClass): array
    {
        $controllers = [];
        foreach ($this->getRouter()->getRouteCollection() as $route) {
            $controller = $route->getDefault('_controller');
            if (!is_string($controller)) {
                continue;
            }

            $controllers[] = $controller;
        }

        self::assertNotSame([], $this->filterControllersByClass($controllers, ReservationSearchController::class));

        return $this->filterControllersByClass($controllers, $controllerClass);
    }

    /**
     * @param array<int, string> $controllers
     *
     * @return array<int, string>
     */
    private function filterControllersByClass(array $controllers, string $controllerClass): array
    {
        return array_values(array_filter(
            $controllers,
            static fn (string $routedController): bool => str_contains($routedController, $controllerClass),
        ));
    }

    /**
     * @return array<int, string>
     */
    protected function getViolatedFields(KernelBrowser $client): array
    {
        $violations = $this->getResponseBody($client)['violations'] ?? null;
        self::assertIsArray($violations);

        $fields = [];
        foreach ($violations as $violation) {
            self::assertIsArray($violation);
            self::assertIsString($violation['propertyPath']);
            $fields[] = $violation['propertyPath'];
        }

        return $fields;
    }
}
