<?php
/*
* This file is part of EC-CUBE
*
* Copyright(c) 2000-2015 LOCKON CO.,LTD. All Rights Reserved.
* http://www.lockon.co.jp/
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Plugin\SalesReport\Service;

use Eccube\Application;
use Faker\Provider\cs_CZ\DateTime;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class SalesReportService
 */
class SalesReportService
{
    /**
     * @var Application $app
     */
    private $app;

    /**
     * @var string $reportType
     */
    private $reportType;

    /**
     * @var DateTime $termStart
     */
    private $termStart;

    /**
     * @var DateTime $termEnd
     */
    private $termEnd;

    /**
     * @var int $unit
     */
    private $unit;

    /**
     * SalesReportService constructor.
     * @param Application $app
     */
    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * @param string $reportType
     * @return SalesReportService $this
     */
    public function setReportType($reportType)
    {
        $this->reportType = $reportType;

        return $this;
    }

    /**
     * @param string  $termType
     * @param Request $request
     * @return SalesReportService $this
     */
    public function setTerm($termType, $request)
    {
        // termStart <= X < termEnd となるように整形する
        if ($termType === 'monthly') {
            $date = $request['monthly'];
            $start = $date->format("Y-m-01 00:00:00");
            $end = $date
                ->modify('+ 1 month')
                ->format("Y-m-01 00:00:00");

            $this
                ->setTermStart($start)
                ->setTermEnd($end);
        } else {
            $start = $request['term_start']
                ->format("Y-m-d 00:00:00");
            $end = $request['term_end']
                ->modify('+ 1 day')
                ->format("Y-m-d 00:00:00");

            $this
                ->setTermStart($start)
                ->setTermEnd($end);
        }

        // 集計単位をせってい
        if (isset($request['unit'])) {
            $this->unit = $request['unit'];
        }

        return $this;
    }

    /**
     * @param $term
     * @return $this
     */
    private function setTermStart($term)
    {
        $this->termStart = $term;

        return $this;
    }

    /**
     * @param $term
     * @return $this
     */
    private function setTermEnd($term)
    {
        $this->termEnd = $term;

        return $this;
    }

    /**
     * @return array
     */
    public function getData()
    {
        $app = $this->app;

        $excludes = array(
            $app['config']['order_processing'],
            $app['config']['order_cancel'],
            $app['config']['order_pending'],
        );

        /* @var $qb \Doctrine\ORM\QueryBuilder */
        $qb = $app['orm.em']->createQueryBuilder();
        $qb
            ->select('o')
            ->from('Eccube\Entity\Order', 'o')
            ->andWhere('o.del_flg = 0')
            ->andWhere('o.order_date >= :start')
            ->andWhere('o.order_date <= :end')
            ->andWhere('o.OrderStatus NOT IN (:excludes)')
            ->setParameter(':excludes', $excludes)
            ->setParameter(':start', $this->termStart)
            ->setParameter(':end', $this->termEnd);

        if ($this->reportType == 'product') {
            $qb->orderBy('o.total', 'DESC');
        }

        $result = array();
        try {
            $result = $qb->getQuery()->getResult();
        } catch (NoResultException $e) {
        }

        return $this->convert($result);
    }

    /**
     * @param $data
     * @return array
     */
    private function convert($data)
    {
        $result = array();
        switch ($this->reportType) {
            case 'term':
                $result = $this->convertByTerm($data);
                break;
            case 'product':
                $result = $this->convertByProduct($data);
                break;
            case 'age':
                $result = $this->convertByAge($data);
                break;
        }

        return $result;
    }

    /**
     * @param $data
     * @return array
     */
    private function convertByTerm($data)
    {
        $start = new \DateTime($this->termStart);
        $end = new \DateTime($this->termEnd);

        $format = $this->formatUnit();

        $raw = array();
        $price = array();

        for ($start; $start < $end; $start = $start->modify('+ 1 Hour')) {
            $date = $start->format($format);
            $raw[$date] = array(
                'price' => 0,
                'time' => 0,
            );
            $price[$date] = 0;
        }

        foreach ($data as $Order) {
            /* @var $Order \Eccube\Entity\Order */
            $orderDate = $Order
                ->getOrderDate()
                ->format($format);
            $price[$orderDate] += $Order->getPaymentTotal();
            $raw[$orderDate]['price'] += $Order->getPaymentTotal();
            $raw[$orderDate]['time'] ++;
        }

        $graph = array(
            'labels' => array_keys($price),
            'datasets' => [
                array(
                    'label'=> '購入合計',
                    'data' => array_values($price),
                    'lineTension' => 0.1,
                    'backgroundColor' => 'rgba(75,192,192,0.4)',
                    'borderColor' => 'rgba(75,192,192,1)',
                    'borderCapStyle' => 'butt',
                    'borderDash' => array(),
                    'borderDashOffset' => 0.0,
                    'borderJoinStyle' => 'miter',
                    'pointBorderColor' => 'rgba(75,192,192,1)',
                    'pointBackgroundColor' => '#fff',
                    'pointBorderWidth' => 1,
                    'pointHoverRadius' => 5,
                    'pointHoverBackgroundColor' => 'rgba(75,192,192,1)',
                    'pointHoverBorderColor' => 'rgba(220,220,220,1)',
                    'pointHoverBorderWidth' => 2,
                    'pointRadius' => 1,
                    'pointHitRadius' => 10,
                    'spanGaps' => false,
                ),
            ],
        );

        return array(
            'raw' => $raw,
            'graph' => $graph,
        );
    }

    /**
     * @return mixed
     */
    private function formatUnit()
    {
        $unit = array(
            'byDay' => 'm/d',
            'byMonth' => 'm',
            'byWeekDay' => 'D',
            'byHour' => 'H',
        );

        return $unit[$this->unit];
    }

    /**
     * @param $data
     * @return array
     */
    private function convertByProduct($data)
    {
        $products = array();
        foreach ($data as $Order) {
            /* @var $Order \Eccube\Entity\Order */
            $OrderDetails = $Order->getOrderDetails();
            foreach ($OrderDetails as $OrderDetail) {
                /* @var $OrderDetail \Eccube\Entity\OrderDetail */
                $ProductClass = $OrderDetail->getProductClass();
                $id = $ProductClass->getId();
                if (!array_key_exists($id, $products)) {
                    $products[$id] = array(
                        'ProductClass' => $ProductClass,
                        'total' => 0,
                        'quantity' => 0,
                        'price' => 0,
                        'time' => 0,
                    );
                }
                $products[$id]['quantity'] += $OrderDetail->getQuantity();
                $products[$id]['price'] = $OrderDetail->getPriceIncTax();
                $products[$id]['time'] ++;
            }
        }

        $i = 0;
        $label = array();
        $data = array();
        $backgroundColor = array();

        foreach ($products as $key => $product) {
            $total =  $product['price'] * $product['quantity'];
            $products[$key]['total'] = $total;
            $backgroundColor[$i] = $this->getColor($i);
            if ($i >= 10) {
                $i = 10;
                $data[$i] += $total;
                $label[$i] = 'Other';
            } else {
                $label[$i] = $product['ProductClass']->getProduct()->getName();
                $data[$i] = $total;
                $i++;
            }
        }

        $result = array(
            'labels' => $label,
            'datasets' => [
                array(
                    'data' => $data,
                    'backgroundColor' => $backgroundColor,
                ),
            ],
        );

        return array(
            'raw' => $products,
            'graph' => $result,
        );
    }

    /**
     * @param $index
     * @return mixed
     */
    private function getColor($index)
    {
        $map = array(
            "#FF6384",
            "#36A2EB",
            "#FFCE56",
            "#5319e7",
            "#d93f0b",
            "#55a532",
            "#1d76db",
            "#bfd4f2",
            "#cc317c",
            "#006b75",
            "#444",
        );

        return $map[$index];
    }

    /**
     * @param $data
     * @return array
     */
    private function convertByAge($data)
    {
        $raw = array();
        $result = array();
        $now = new \DateTime();
        $backgroundColor = array();
        $i = 0;
        foreach ($data as $Order) {
            /* @var $Order \Eccube\Entity\Order */
            $age = '未回答';

            $Customer = $Order->getCustomer();
            if ($Customer) {
                $birth = $Order->getCustomer()->getBirth();
                if (!empty($birth)) {
                    $age = (floor($birth->diff($now)->y / 10) * 10).'代';
                }
            }
            if (!array_key_exists($age, $result)) {
                $result[$age] = 0;
                $raw[$age] = array(
                    'total' => 0,
                    'time' => 0,
                );
            }
            $result[$age] += $Order->getPaymentTotal();
            $raw[$age]['total'] += $Order->getPaymentTotal();
            $raw[$age]['time'] ++;
            $backgroundColor[$i] = $this->getColor($i);
            $i++;
        }

        $graph = array(
            'labels' => array_keys($result),
            'datasets' => [
                array(
                    'label' => "購入合計",
                    'backgroundColor' => $backgroundColor,
                    'borderColor' => $backgroundColor,
                    'borderWidth' => 1,
                    'data' => array_values($result),
                ),
            ],
        );

        return array(
            'raw' => $raw,
            'graph' => $graph,
        );
    }
}
