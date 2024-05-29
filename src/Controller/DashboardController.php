<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Subscription;
use App\Repository\SubscriptionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

class DashboardController extends AbstractController
{
    private const MONTHS = [
        'January',
        'February',
        'March',
        'April',
        'May',
        'June',
        'July',
        'August',
        'September',
        'October',
        'November',
        'December'
    ];

    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(
        SubscriptionRepository $subscriptionRepository,
        ChartBuilderInterface $chartBuilder,
    ): Response {
        $subscriptions = $subscriptionRepository->findBy(['owner' => $this->getUser()]);
        $chart = $chartBuilder->createChart(Chart::TYPE_BAR);
        $dataSets = $this->createDataSets($subscriptions);

        $chart->setData([
                            'labels' => self::MONTHS,
                            'datasets' => $dataSets,
                        ]);

        $chart->setOptions([
                               'scales' => [
                                   'x' => [
                                       'stacked' => true,
                                   ],
                                   'y' => [
                                       'stacked' => true,
                                   ],
                               ],
                           ]);

        return $this->render('dashboard/index.html.twig', [
            'subscriptions' => $subscriptions,
            'chart' => $chart,
        ]);
    }

    private function countByMonth(array $subscriptions, int $month): float
    {
        $total = 0.0;

        foreach ($subscriptions as $subscription) {
            if ($subscription->getMonthly() !== null) {
                $total += $subscription->getMonthly();
            } elseif ((int)$subscription->getFirstPayment()->format('n') === ($month + 1)) {
                $total += $subscription->getYearly();
            }
        }

        return $total;
    }

    private function createDataSets(array $subscriptions): array
    {
        $dataSets = [];
        foreach ($subscriptions as $subscription) {
            $dataSets[] = [
                'label' => $subscription->getName(),
                'data' => $this->createData($subscription),
                'backgroundColor' => $this->randomColor(),
            ];
        }

        return $dataSets;
    }

    private function createData(Subscription $subscription): array
    {
        $data = [];
        foreach (array_keys(self::MONTHS) as $month) {
            $data[] = $this->countByMonth([$subscription], $month);
        }

        return $data;
    }

    private function randomColor(): string
    {
        return 'rgb(' . random_int(0, 255) . ', ' . random_int(0, 255) . ', ' . random_int(0, 255) . ')';
    }
}
