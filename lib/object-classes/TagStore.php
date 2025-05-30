<?php

/**
 * ISC License
 *
 * Copyright (c) 2014-2018, Palo Alto Networks Inc.
 * Copyright (c) 2019, Palo Alto Networks Inc.
 * Copyright (c) 2024, Sven Waschkut - pan-os-php@waschkut.net
 *
 * Permission to use, copy, modify, and/or distribute this software for any
 * purpose with or without fee is hereby granted, provided that the above
 * copyright notice and this permission notice appear in all copies.
 *
 * THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
 * WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
 * MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
 * ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
 * WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
 * ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
 * OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
 */

/**
 * Class TagStore
 * @property Tag[] $o
 * @property VirtualSystem|DeviceGroup|PanoramaConf|PANConf|Container|DeviceCloud $owner
 * @method Tag[] getAll()
 */
class TagStore extends ObjStore
{
    /** @var null|TagStore */
    public $parentCentralStore = null;

    public static $childn = 'Tag';


    public function __construct($owner)
    {
        $this->classn = &self::$childn;

        $this->owner = $owner;
        $this->o = array();

        $this->setParentCentralStore( 'tagStore' );
    }

    /**
     * @param $name
     * @param null $ref
     * @param bool $nested
     * @return null|Tag
     */
    //Todo: check if $nested = false; must be set
    #NEW - public function find($name, $ref = null, $nested = FALSE)
    public function find($name, $ref = null, $nested = TRUE)
    {
        TAG::replaceNamewith( $name );
        $f = $this->findByName($name, $ref, $nested);

        if( $f !== null )
            return $f;

        if( $nested && $this->parentCentralStore !== null )
            return $this->parentCentralStore->find($name, $ref, $nested);

        return null;
    }

    public function removeAllTags()
    {
        $this->removeAll();
        $this->rewriteXML();
    }

    /**
     * Returns an Array with all Address , AddressGroups, TmpAddress objects in this store
     * @param $withFilter string|null
     * @param bool $sortByDependencies
     * @return Address[]|AddressGroup[]
     */
    public function all($withFilter = null, $sortByDependencies = FALSE)
    {
        $query = null;

        if( $withFilter !== null && $withFilter != '' )
        {
            $errMesg = '';
            $query = new RQuery('tag');
            if( $query->parseFromString($withFilter, $errMsg) === FALSE )
            {
                mwarning("error while parsing query: {$errMesg} - filter: {$withFilter}", null, FALSE);
                return array();
            }


            $res = array();
            foreach( $this->getAll() as $obj )
            {
                if( $query->matchSingleObject($obj) )
                    $res[] = $obj;
            }
            return $res;
        }

        if( !$sortByDependencies )
            return $this->getAll();

        $result = array();

        foreach( $this->getAll() as $object )
            $result[] = $object;

        /*
        foreach( $this->_addressObjects as $object )
            $result[] = $object;

        foreach( $this->addressGroups(TRUE) as $object )
            $result[] = $object;

        foreach( $this->_regionObjects as $object )
            $result[] = $object;
        */

        return $result;
    }

    /**
     * add a Tag to this store. Use at your own risk.
     * @param Tag $Obj
     * @param bool
     * @return bool
     */
    public function addTag(Tag $Obj, $rewriteXML = TRUE)
    {
        $ret = $this->add($Obj);
        if( $ret && $rewriteXML )
        {
            if( $this->xmlroot === null )
                $this->xmlroot = DH::findFirstElementOrCreate('tag', $this->owner->xmlroot);

            $this->xmlroot->appendChild($Obj->xmlroot);
        }
        return $ret;
    }

    /**
     * @param string $base
     * @param string $suffix
     * @param integer|string $startCount
     * @return string
     */
    public function findAvailableTagName($base, $suffix, $startCount = '')
    {
        TAG::replaceNamewith( $base );

        $maxl = 31;
        $basel = strlen($base);
        $suffixl = strlen($suffix);
        $inc = $startCount;
        $basePlusSuffixL = $basel + $suffixl;

        while( TRUE )
        {
            $incl = strlen(strval($inc));

            if( $basePlusSuffixL + $incl > $maxl )
            {
                $newname = substr($base, 0, $basel - $suffixl - $incl) . $suffix . $inc;
            }
            else
                $newname = $base . $suffix . $inc;

            if( $this->find($newname) === null )
                return $newname;

            if( $startCount == '' )
                $startCount = 0;
            $inc++;
        }
    }


    /**
     * return tags in this store
     * @return Tag[]
     */
    public function tags()
    {
        return $this->o;
    }

    function createTag($name, $ref = null)
    {
        if( $this->find($name, null, FALSE) !== null )
            derr('Tag named "' . $name . '" already exists, cannot create');

        if( $this->xmlroot === null )
        {
            if( $this->owner->isDeviceGroup() || $this->owner->isVirtualSystem() || $this->owner->isContainer() || $this->owner->isDeviceCloud() )
                $this->xmlroot = DH::findFirstElementOrCreate('tag', $this->owner->xmlroot);
            else
                $this->xmlroot = DH::findFirstElementOrCreate('tag', $this->owner->sharedroot);
        }

        $newTag = new Tag($name, $this);
        $newTag->owner = null;

        $newTagRoot = DH::importXmlStringOrDie($this->owner->xmlroot->ownerDocument, Tag::$templatexml);
        $newTagRoot->setAttribute('name', $name);
        $newTag->load_from_domxml($newTagRoot);

        if( $ref !== null )
            $newTag->addReference($ref);

        $this->addTag($newTag);

        return $newTag;
    }

    function findOrCreate($name, $ref = null, $nested = TRUE)
    {
        TAG::replaceNamewith( $name );
        $f = $this->find($name, $ref, $nested);

        if( $f !== null )
            return $f;

        return $this->createTag($name, $ref);
    }

    function API_createTag($name, $ref = null)
    {
        $newTag = $this->createTag($name, $ref);

        if( !$newTag->isTmp() )
        {
            $xpath = $this->getXPath();
            $con = findConnectorOrDie($this);
            $element = $newTag->getXmlText_inline();
            if( $con->isAPI())
                $con->sendSetRequest($xpath, $element);
            elseif( $con->isSaseAPI() )
                $con->sendCreateRequest($newTag);
        }

        return $newTag;
    }


    /**
     * @param Tag $tag
     *
     * @return bool  True if Zone was found and removed. False if not found.
     */
    public function removeTag(Tag $tag)
    {
        $ret = $this->remove($tag);

        if( $ret && !$tag->isTmp() && $this->xmlroot !== null )
        {
            if( $this->count() > 0 )
                $this->xmlroot->removeChild($tag->xmlroot);
            else
                DH::clearDomNodeChilds($this->xmlroot);
        }

        return $ret;
    }

    /**
     * @param Tag $tag
     * @return bool
     */
    public function API_removeTag(Tag $tag)
    {
        $xpath = null;

        if( !$tag->isTmp() )
            $xpath = $tag->getXPath();

        $ret = $this->removeTag($tag);

        if( $ret && !$tag->isTmp() )
        {
            $con = findConnectorOrDie($this);
            if( $con->isAPI())
                $con->sendDeleteRequest($xpath);
            elseif( $con->isSaseAPI())
                $con->sendDELETERequest($tag);
        }

        return $ret;
    }

    public function &getXPath()
    {
        $str = '';

        if( $this->owner->isDeviceGroup() || $this->owner->isVirtualSystem() || $this->owner->isContainer() || $this->owner->isDeviceCloud() )
            $str = $this->owner->getXPath();
        elseif( $this->owner->isPanorama() || $this->owner->isFirewall() )
            $str = '/config/shared';
        else
            derr('unsupported');

        $str = $str . '/tag';

        return $str;
    }


    private function &getBaseXPath()
    {
        if( $this->owner->isPanorama() || $this->owner->isFirewall() )
        {
            $str = "/config/shared";
        }
        else
            $str = $this->owner->getXPath();


        return $str;
    }

    public function &getTagStoreXPath()
    {
        $path = $this->getBaseXPath() . '/tag';
        return $path;
    }

    public function rewriteXML()
    {
        if( count($this->o) > 0 )
        {
            if( $this->xmlroot === null )
                return;

            $this->xmlroot->parentNode->removeChild($this->xmlroot);
            $this->xmlroot = null;
        }

        if( $this->xmlroot === null )
        {
            if( count($this->o) > 0 )
                DH::findFirstElementOrCreate('tag', $this->owner->xmlroot);
        }

        DH::clearDomNodeChilds($this->xmlroot);
        foreach( $this->o as $o )
        {
            if( !$o->isTmp() )
                $this->xmlroot->appendChild($o->xmlroot);
        }
    }


    /**
     * @return Tag[]
     */
    public function nestedPointOfView()
    {
        $current = $this;

        $objects = array();

        while( TRUE )
        {
            if( get_class( $current->owner ) == "PanoramaConf" )
                $location = "shared";
            else
                $location = $current->owner->name();

            foreach( $current->o as $o )
            {
                if( !isset($objects[$o->name()]) )
                    $objects[$o->name()] = $o;
                else
                {
                    $tmp_o = &$objects[ $o->name() ];
                    $tmp_ref_count = $tmp_o->countReferences();

                    if( $tmp_ref_count == 0 )
                    {
                        //Todo: check if object value is same; if same to not add ref
                        if( $location != "shared" )
                            foreach( $o->refrules as $ref )
                                $tmp_o->addReference( $ref );
                    }
                }
            }

            if( isset($current->owner->parentDeviceGroup) && $current->owner->parentDeviceGroup !== null )
                $current = $current->owner->parentDeviceGroup->tagStore;
            elseif( isset($current->owner->parentContainer) && $current->owner->parentContainer !== null )
                $current = $current->owner->parentContainer->tagStore;
            elseif( isset($current->owner->owner) && $current->owner->owner !== null && !$current->owner->owner->isFawkes() && !$current->owner->owner->isBuckbeak() )
                $current = $current->owner->owner->tagStore;
            else
                break;
        }

        return $objects;
    }

    public function storeName()
    {
        return "tagStore";
    }
}


