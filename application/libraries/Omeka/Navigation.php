<?php
/**
 * @copyright Roy Rosenzweig Center for History and New Media, 2007-2010
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 * @package Omeka
 */

/**
 * Customized subclass of Zend Framework's Zend_Navigation class.
 *
 *
 * @package Omeka
 * @copyright Roy Rosenzweig Center for History and New Media, 2007-2010
 */
class Omeka_Navigation extends Zend_Navigation
{
    const PUBLIC_NAVIGATION_MAIN_OPTION_NAME = 'public_navigation_main';
           
    /**
     * Creates a new navigation container
     *
     * @param array|Zend_Config $pages    [optional] pages to add
     * @throws Zend_Navigation_Exception  if $pages is invalid
     */
    public function __construct($pages = null)
    {
        parent::__construct($pages);
    }
    
    /**
     * Saves the navigation in the global options table.
     *
     * @param String $optionName    The name of the option
     */
    public function saveAsOption($optionName) 
    {
        set_option($optionName, json_encode($this->toArray()));
    }
    
    /**
     * Loads the navigation from the global options table
     *
     * @param String $optionName    The name of the option
     */
    public function loadAsOption($optionName) 
    {
        if ($navPages = json_decode(get_option($optionName), true)) {
            $this->setPages($navPages);
        }
    }
    
    /**
     * Adds a page to the container.  If a page does not have a valid id, it will give it one.
     * and is an instance of Zend_Navigation_Page_Mvc or Omeka_Navigation_Page_Uri.
     * If a page already has another page with the same uid then it will not add the page.
     *
     * This method will inject the container as the given page's parent by
     * calling {@link Zend_Navigation_Page::setParent()}.
     *
     * @param  Zend_Navigation_Page|array|Zend_Config $page  page to add
     * @return Zend_Navigation_Container                     fluent interface,
     *                                                       returns self
     * @throws Zend_Navigation_Exception                     if page is invalid
     */
    public function addPage($page)
    {
        if ($page === $this) {
            require_once 'Zend/Navigation/Exception.php';
            throw new Zend_Navigation_Exception(
                'A page cannot have itself as a parent');
        }

        if (is_array($page) || $page instanceof Zend_Config) {
            require_once 'Zend/Navigation/Page.php';
            $page = Zend_Navigation_Page::factory($page);
        }
        
        if (!($page instanceof Zend_Navigation_Page_Mvc || $page instanceof Omeka_Navigation_Page_Uri)) {
            require_once 'Zend/Navigation/Exception.php';
            throw new Zend_Navigation_Exception(
                    'Invalid argument: $page must be an instance of ' .
                    'Zend_Navigation_Page_Mvc or Omeka_Navigation_Page_Uri');
        }
                
        $page->uid = $this->createPageUid($page->getLabel(), $page->getHref());        
        
        if (!($fPage = $this->findByUid($page->uid))) {
            return parent::addPage($page);
        }
        
        return $this;
    }
    
    /**
     * Adds pages generated by Omeka plugins and other contributors via a filter (e.x. 'public_navigation_main').
     * The filter should provide an associative array where the key is a link label and the value
     * is either a uri, Omeka_Navigation_Page_Uri, Zend_Navigation_Page_Uri, or Zend_Navigation_Page_Mvc.  Before
     * adding the values to this navigation object, will normalize and convert all values to either an
     * Omeka_Navigation_Page_Uri or a Zend_Navigation_Page_Mvc.  
     * If the associated uri of any page is invalid, it will not add that page to the navigation. 
     * Also, it removes expired pages from formerly active plugins and other former handlers of the filter.
     * 
     */
    public function addPagesFromFilter($filterName='public_navigation_main') 
    {                
        // get default pages for the filter
        $pageLinks = array();
        switch($filterName) {
            case 'public_navigation_main':
                // add the standard Browse Items and Browse Collections links to the main nav
                $pageLinks = array(
                    __('Browse Items') => new Zend_Navigation_Page_Mvc(array('controller' => 'items', 'action' => 'browse', 'visible' => true)), 
                    __('Browse Collections') => new Zend_Navigation_Page_Mvc(array('controller' => 'collections', 'action' => 'browse', 'visible' => true)),                    
                    );
            break;
        }
        
        // gather other page links from filter handlers (e.g. plugins)      
        $pageLinks = apply_filters($filterName, $pageLinks);        
                                        
        // add pages from filter handlers (e.g. plugins)
        $pageUids = array();
        foreach($pageLinks as $label => $uriOrPage) {
            
            // figure out the type of page
            $page = null;
            if (is_string($uriOrPage)) {
                $page = new Omeka_Navigation_Page_Uri();
                $page->setUri($uriOrPage);
                $page->setHref($page->getHref());
                $page->setVisible(false);
            } elseif ($uriOrPage instanceof Omeka_Navigation_Page_Uri) {
                $page = $uriOrPage;
                $page->setHref($page->getHref());
            } elseif ($uriOrPage instanceof Zend_Navigation_Page_Mvc) {
                $page = $uriOrPage;
                if ($page->getRoute() === null) {
                    $page->setRoute('default');
                }
            }
    
            if ($page) {
                // set the page link label
                $page->setLabel($label);
                
                // if the navigation does not have the page, then add it
                $pUid = $this->createPageUid($page->getLabel(), $page->getHref());
                $pageUids[] = $pUid; // gather the uids of pages offered by filters
                
                if (!($fPage = $this->getPageByUid($pUid))) {                    
                    // initialize the page with settings for pages that come from filters.
                    $page->set('can_delete', false); // make sure the user cannot manually delete the navigation link
                    $this->addPage($page); // add the new page
                }
            }
        }
                        
        // remove old pages that cannot be deleted and which are not provided by plugins or other filter handlers
        $expiredPages = array();
        foreach($this as $page) {
            if (!$page->can_delete && !in_array($page->uid, $pageUids)) {
                $expiredPages[] = $page;
            }
        }
        foreach($expiredPages as $expiredPage) {
            $this->removePage($expiredPage);
        }
    }
        
    /**
     * Returns the navigation page associated with uid.  If not page is associated, then it returns null.
     *
     * @param String $pageUid The uid of the page
     * @return Omeka_Zend_Navigation_Page_Uri|Zend_Navigation_Page_Mvc|null
     */
    public function getPageByUid($pageUid)
    {
        if ($page = $this->findOneBy('uid', $pageUid)) {
            return $page;
        }
        return null;
    }
    
    /**
     * Returns the unique id for the page, which can be used to determine whether it can be added to the navigaton
     * It is based on the href (and not just the uri) because the href has the fragment included.
     * It is also based on the label of the link.
     *
     * @param String $label
     * @param String $href
     * @return String
     */
    public function createPageUid($label, $href) 
    {
        return $href. '|' . $label;
    }
    
    /**
     * Returns the option value associated with the default navigation during installation 
     *
     * @param String $optionName The option name for a stored navigation object.
     * @return String The option value associated with the default navigation during installation.
     * If no option is found for the option name, then it returns an empty string.
     */
    public static function getNavigationOptionValueForInstall($optionName) 
    {
        $v = '';
        $nav = new Omeka_Navigation();
        switch($optionName) {
            case self::PUBLIC_NAVIGATION_MAIN_OPTION_NAME:
                $nav->addPagesFromFilter('public_navigation_main');
            break;
        }
                
        if ($nav->count()) {
            $v = json_encode($nav->toArray());
        }
        return $v;
    }
}
