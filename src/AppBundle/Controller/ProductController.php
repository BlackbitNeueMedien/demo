<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace AppBundle\Controller;

use AppBundle\Model\Product\AbstractProduct;
use AppBundle\Model\Product\AccessoryPart;
use AppBundle\Model\Product\Car;
use AppBundle\Model\Product\Category;
use AppBundle\Services\SegmentTrackingHelperService;
use AppBundle\Website\LinkGenerator\ProductLinkGenerator;
use AppBundle\Website\Navigation\BreadcrumbHelperService;
use Pimcore\Bundle\EcommerceFrameworkBundle\Factory;
use Pimcore\Bundle\EcommerceFrameworkBundle\FilterService\Helper;
use Pimcore\Bundle\EcommerceFrameworkBundle\IndexService\ProductList\ProductListInterface;
use Pimcore\Config;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\FilterDefinition;
use Pimcore\Templating\Helper\HeadTitle;
use Pimcore\Templating\Model\ViewModel;
use Pimcore\Translation\Translator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Zend\Paginator\Paginator;

class ProductController extends BaseController
{
    /**
     * @Route("/shop/{path}{productname}~p{product}", name="shop-detail", defaults={"path"=""}, requirements={"path"=".*?", "productname"="[\w-]+", "product"="\d+"})
     *
     * @param Request $request
     * @param HeadTitle $headTitleHelper
     * @param BreadcrumbHelperService $breadcrumbHelperService
     * @param Factory $factory
     * @param SegmentTrackingHelperService $segmentTrackingHelperService
     *
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Exception
     */
    public function detailAction(Request $request, HeadTitle $headTitleHelper, BreadcrumbHelperService $breadcrumbHelperService, Factory $factory, SegmentTrackingHelperService $segmentTrackingHelperService)
    {
        $product = Concrete::getById($request->get('product'));

        if (!(
                $product && ($product->isPublished() && (($product instanceof Car && $product->getObjectType() == Car::OBJECT_TYPE_ACTUAL_CAR) || $product instanceof AccessoryPart) || $this->verifyPreviewRequest($request, $product))
            )
        ) {
            throw new NotFoundHttpException('Product not found.');
        }

        $breadcrumbHelperService->enrichProductDetailPage($product);
        $headTitleHelper($product->getOSName());

        $paramBag = $this->view->getAllParameters();
        $paramBag['product'] = $product;

        //track segments for personalization
        $segmentTrackingHelperService->trackSegmentsForProduct($product);

        if ($product instanceof Car) {
            return $this->render('product/detail.html.twig', $paramBag);
        } elseif ($product instanceof AccessoryPart) {

            // get all compatible products
            $productList = $factory->getIndexService()->getProductListForCurrentTenant();
            $productList->setVariantMode(ProductListInterface::VARIANT_MODE_VARIANTS_ONLY);
            $productList->addCondition('o_id IN (' . implode(',', $product->getCompatibleToProductIds()) . ')', 'o_id');
            $paramBag['compatibleTo'] = $productList;

            return $this->render('product/detail_accessory.html.twig', $paramBag);
        }
    }

    /**
     * @Route("/shop/{path}{categoryname}~c{category}", name="shop-category", defaults={"path"=""}, requirements={"path"=".*?", "categoryname"="[\w-]+", "category"="\d+"})
     *
     * @param Request $request
     * @param HeadTitle $headTitleHelper
     * @param BreadcrumbHelperService $breadcrumbHelperService
     * @param Factory $ecommerceFactory
     * @param SegmentTrackingHelperService $segmentTrackingHelperService
     *
     * @return array|\Symfony\Component\HttpFoundation\Response
     */
    public function listingAction(Request $request, HeadTitle $headTitleHelper, BreadcrumbHelperService $breadcrumbHelperService, Factory $ecommerceFactory, SegmentTrackingHelperService $segmentTrackingHelperService)
    {
        $viewModel = new ViewModel();
        $params = array_merge($request->query->all(), $request->attributes->all());

        //needed to make sure category filter filters for active category
        $params['parentCategoryIds'] = $params['category'];

        $category = Category::getById($params['category']);
        $viewModel->category = $category;
        if ($category) {
            $headTitleHelper($category->getName());
            $breadcrumbHelperService->enrichCategoryPage($category);
        }

        $indexService = $ecommerceFactory->getIndexService();
        $productListing = $indexService->getProductListForCurrentTenant();
        $productListing->setVariantMode(ProductListInterface::VARIANT_MODE_VARIANTS_ONLY);
        $viewModel->productListing = $productListing;

        // load current filter
        if ($category) {
            $filterDefinition = $category->getFilterdefinition();

            //track segments for personalization
            $segmentTrackingHelperService->trackSegmentsForCategory($category);

//            $trackingManager = Factory::getInstance()->getTrackingManager();
//            $trackingManager->trackCategoryPageView($category->getName(), null);
        }

        if ($request->get('filterdefinition') instanceof FilterDefinition) {
            $filterDefinition = $request->get('filterdefinition');
        }

        if (empty($filterDefinition)) {
            $filterDefinition = Config::getWebsiteConfig()->get('fallbackFilterdefinition');
        }

        $filterService = $ecommerceFactory->getFilterService();
        Helper::setupProductList($filterDefinition, $productListing, $params, $viewModel, $filterService, true);
        $viewModel->filterService = $filterService;
        $viewModel->filterDefinition = $filterDefinition;

        // init pagination
        $paginator = new Paginator($productListing);
        $paginator->setCurrentPageNumber($request->get('page'));
        $paginator->setItemCountPerPage(18);
        $paginator->setPageRange(5);
        $viewModel->results = $paginator;
        $viewModel->paginationVariables = $paginator->getPages('Sliding');

        if ($request->attributes->get('noLayout')) {
            return $this->render('/product/listing_content.html.twig', array_merge($this->view->getAllParameters(), $viewModel->getAllParameters()));
        }

        return $viewModel->getAllParameters();
    }

    public function productTeaserAction(Request $request)
    {
        $paramsBag = [];
        if ($request->get('type') == 'object') {
            AbstractObject::setGetInheritedValues(true);
            $product = AbstractProduct::getById($request->get('id'));

            $paramsBag['product'] = $product;
            //$trackingManager = Factory::getInstance()->getTrackingManager();
            //$trackingManager->trackProductImpression($product);
            return $this->render('/product/product_teaser.html.twig', $paramsBag);
        }

        throw new NotFoundHttpException('Product not found.');
    }

    /**
     * @Route("/search", name="search")
     *
     * @param Request $request
     * @param Factory $ecommerceFactory
     * @param ProductLinkGenerator $productLinkGenerator
     * @param Translator $translator
     * @param BreadcrumbHelperService $breadcrumbHelperService
     * @param HeadTitle $headTitleHelper
     *
     * @return array|\Symfony\Component\HttpFoundation\JsonResponse
     */
    public function searchAction(Request $request, Factory $ecommerceFactory, ProductLinkGenerator $productLinkGenerator, Translator $translator, BreadcrumbHelperService $breadcrumbHelperService, HeadTitle $headTitleHelper)
    {
        $params = $request->query->all();
        $viewModel = new ViewModel();

        $viewModel->category = Category::getById($params['category']);

        $indexService = $ecommerceFactory->getIndexService();
        $productListing = $indexService->getProductListForCurrentTenant();
        $productListing->setVariantMode(ProductListInterface::VARIANT_MODE_VARIANTS_ONLY);

        $term = strip_tags($request->get('term'));
        $term = trim(preg_replace('/\s+/', ' ', $term));

        if (!empty($term)) {
            foreach (explode(' ', $term) as $t) {
                $productListing->addQueryCondition($t, 'search');
            }
        }

        if ($params['autocomplete']) {
            $resultset = [];
            $productListing->setLimit(10);
            foreach ($productListing as $product) {
                $result['href'] = $productLinkGenerator->generate($product, []);
                if ($product instanceof Car) {
                    $result['product'] = $product->getOSName() . ' ' . $product->getColor()[0] . ', ' . $product->getCarClass();
                } else {
                    $result['product'] = $product->getOSName();
                }

                $resultset[] = $result;
            }

            return $this->json($resultset);
        }

        $filterDefinition = $viewModel->filterDefinition = Config::getWebsiteConfig()->get('fallbackFilterdefinition');

        // create and init filter service
        $filterService = Factory::getInstance()->getFilterService();

        Helper::setupProductList($filterDefinition, $productListing, $params, $viewModel, $filterService, true);
        $viewModel->filterService = $filterService;
        $viewModel->products = $productListing;

        // init pagination
        $paginator = new Paginator($productListing);
        $paginator->setCurrentPageNumber($request->get('page'));
        $paginator->setItemCountPerPage(18);
        $paginator->setPageRange(5);
        $viewModel->results = $paginator;
        $viewModel->paginationVariables = $paginator->getPages('Sliding');

//        $trackingManager = Factory::getInstance()->getTrackingManager();
//        foreach ($paginator as $product) {
//            $trackingManager->trackProductImpression($product);
//        }

        //breadcrumbs
        $placeholder = $this->get('pimcore.templating.view_helper.placeholder');
        $placeholder('addBreadcrumb')->append([
            'parentId' => $this->document->getId(),
            'id' => 'search-result',
            'label' => $translator->trans('shop.search-result', [$term])
        ]);

        $viewModel->language = $request->getLocale();
        $viewModel->term = $term;

        $breadcrumbHelperService->enrichGenericDynamicPage($translator->trans('shop.search-result', [$term]));
        $headTitleHelper($translator->trans('shop.search-result', [$term]));

        return $viewModel->getAllParameters();
    }
}
