<?php

namespace Dywee\ProductCMSBundle\Service;

use Doctrine\ORM\EntityManager;
use Dywee\ProductBundle\Entity\BaseProduct;
use Dywee\ProductBundle\Entity\ProductStat;
use Dywee\ProductCMSBundle\DyweeProductCMSEvent;
use Dywee\ProductCMSBundle\Event\ProductStatEvent;
use Symfony\Component\HttpFoundation\Session\Session;

class ProductStatManager{

    private $em;
    private $sessionManager;

    public function __construct(EntityManager $entityManager, StatSessionManager $sessionManager)
    {
        $this->em = $entityManager;
        $this->sessionManager = $sessionManager;
    }

    public function handleEvent(ProductStatEvent $productStatEvent)
    {
        switch($productStatEvent->getEvent()){
            case DyweeProductCMSEvent::PRODUCT_PAGE_DISPLAY:
                return $this->createStatForDisplay($productStatEvent->getProduct());
            case DyweeProductCMSEvent::PRODUCT_ADD_TO_BASKET:
                return $this->createStatForBasket($productStatEvent->getProduct());
            case DyweeProductCMSEvent::PRODUCT_PURCHASED:
                return $this->createStatForOrder($productStatEvent->getProduct());
        }
    }

    public function createStatForDisplay(BaseProduct $product)
    {
        $productStatRepository = $this->em->getRepository('DyweeProductBundle:ProductStat');

        $productStat = $productStatRepository->findOneBy(array(
            'product'       => $product,
            'type'          => ProductStat::TYPE_DISPLAY,
            'trackingKey'   => $this->sessionManager->getTrackingKey()
        ));

        // TODO vérifier qu'on a bien la même date
        if($productStat)
            $productStat->setQuantity($productStat->getQuantity()+1);
        else{
            $productStat = new ProductStat();
            $productStat->setProduct($product);
            $productStat->setQuantity(1);
            $productStat->setType(ProductStat::TYPE_DISPLAY);
            $productStat->setTrackingKey($this->sessionManager->getTrackingKey());
        }

        $this->em->persist($productStat);
        $this->em->flush();
    }

    public function createStatForBasket(BaseProduct $product, $quantity)
    {
        $productStat = new ProductStat();
        $productStat->setProduct($product);
        $productStat->setQuantity($quantity);
        $productStat->setType(ProductStat::TYPE_ADD_TO_BASKET);
        $productStat->setTrackingKey($this->sessionManager->getTrackingKey());

        $this->em->persist($productStat);
        $this->em->flush();
    }

    public function createStatForOrder(BaseProduct $product, $quantity)
    {
        $productStat = new ProductStat();
        $productStat->setProduct($product);
        $productStat->setQuantity($quantity);
        $productStat->setType(ProductStat::TYPE_BUY);
        $productStat->setTrackingKey($this->sessionManager->getTrackingKey());

        $this->em->persist($productStat);
        $this->em->flush();
        $this->sessionManager->removeTrackingKey();
    }

    public function getForProduct(BaseProduct $product)
    {
        return $this->getForProductAndTimeRange($product);
    }

    public function getForProductAndTimeRange(BaseProduct $product, $beginAt = null, $endAt = null, $timeScale = 'day')
    {
        if(!$beginAt)
            $beginAt = new \DateTime('last month');
        if(!$endAt)
            $endAt = new \DateTime();

        $psr = $this->em->getRepository('DyweeProductBundle:ProductStat');

        $views = $psr->getStats($product, ProductStat::TYPE_DISPLAY, $beginAt, $endAt, $timeScale);
        $baskets = $psr->getStats($product, ProductStat::TYPE_ADD_TO_BASKET, $beginAt, $endAt, $timeScale);
        $sales = $psr->getStats($product, ProductStat::TYPE_BUY, $beginAt, $endAt, $timeScale);

        $stats = array();

        $date = clone $beginAt;

        $diff = (int) $endAt->diff($beginAt)->format('%a');

        for($i = 0; $i <= $diff; $i++)
        {
            $key = $date->modify('+1 day')->format('d/m/Y');
            $stats[$key] = array('createdAt' => $key, 'view' => 0, 'basket' => 0, 'sale' => 0);
        }

        //On organise les données des stats
        foreach($views as $view)
            $stats[$view['createdAt']->format('d/m/Y')]['view'] = $view['total'];

        foreach($baskets as $basket)
            $stats[$basket['createdAt']->format('d/m/Y')]['basket'] = $basket['total'];

        foreach($sales as $sale)
            $stats[$sale['createdAt']->format('d/m/Y')]['sale'] = $sale['total'];

        return $stats;
    }

}