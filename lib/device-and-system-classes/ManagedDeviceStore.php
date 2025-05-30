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
 * Class ManagedDeviceStore
 * @property ManagedDevice[] $o
 * @property PanoramaConf|FawkesConf $owner
 * @method ManagedDevice[] getAll()
 */
class ManagedDeviceStore extends ObjStore
{
    /** @var  PanoramaConf|FawkesConf */
    public $owner;

    /** @var null|TagStore */
    protected $parentCentralStore = null;

    public static $childn = 'ManagedDevice';


    public function __construct($owner)
    {
        $this->classn = &self::$childn;

        $this->owner = $owner;
        $this->o = array();
    }

    public function load_from_domxml(DOMElement $xml)
    {
        $this->xmlroot = $xml;
        $this->owner->managedFirewallsSerials = $this->get_serial_from_xml($xml, TRUE);

    }

    public function get_serial_from_xml(DOMElement $xml, $add_firewall = FALSE)
    {
        $tmp_managedFirewallsSerials = array();

        if( $xml !== FALSE )
        {
            foreach( $xml->childNodes as $node )
            {
                $tmp_obj = null;

                if( $node->nodeType != 1 )
                    continue;
                $serial = DH::findAttribute('name', $node);
                if( $serial === FALSE )
                    derr('no serial found');

                if( $add_firewall )
                {
                    $tmp_obj = $this->find($serial);
                    if( $tmp_obj === null )
                    {
                        $tmp_obj = new ManagedDevice($serial, $this);
                        $tmp_obj->load_from_domxml( $node );
                        $this->add($tmp_obj);
                    }
                }

                $tmp_managedFirewallsSerials[$serial] = $tmp_obj;
            }
        }

        return $tmp_managedFirewallsSerials;
    }

    /**
     * @param $serial
     * @param null $ref
     * @param bool $nested
     * @return null|ManagedDevice
     */
    public function find($serial, $ref = null, $nested = TRUE)
    {
        $f = $this->findByName($serial, $ref, $nested);

        if( $f !== null )
            return $f;

        return null;
    }

    /**
     * @param $serial
     * @param null $ref
     * @param bool $nested
     * @return null|ManagedDevice
     */
    public function findOrCreate($serial, $ref = null, $nested = TRUE)
    {
        $tmp_obj = $this->findByName($serial, $ref, $nested);

        if( $tmp_obj === null )
        {
            $tmp_obj = new ManagedDevice($serial, $this);
            $this->add($tmp_obj);
        }

        return $tmp_obj;
    }

    /**
     * @param $serial
     * @param null $ref
     * @param bool $nested
     * @return ManagedDevice
     */
    public function createManagedDevice($serial, $ref = null, $nested = TRUE, $onPrem = null, $vsys = 'vsys1')
    {
        $tmp_obj = $this->findByName($serial, $ref, $nested);

        if( $tmp_obj === null )
        {
            $tmp_obj = new ManagedDevice($serial, $this);
            $this->add($tmp_obj);

            $entryNode = DH::findFirstElementByNameAttrOrCreate( 'entry', $serial, $this->xmlroot, $this->xmlroot->ownerDocument );
            if( $this->owner->isPanorama() )
            {
                //no more changes needed yet
            }
            elseif( $this->owner->isFawkes() || $this->owner->isBuckbeak() )
            {
                if( $onPrem === null )
                {
                    mwarning( "managedDevice with serial: ".$serial." need a DeviceOnPrem reference" );
                    return null;
                }

                DH::findFirstElementOrCreate( 'device-container', $entryNode, $onPrem );
                $vsysNode = DH::findFirstElementOrCreate( 'vsys', $entryNode);
                $vsysEntryNode = DH::findFirstElementByNameAttrOrCreate( 'entry', $vsys, $vsysNode, $this->xmlroot->ownerDocument );

                $vsysNode = DH::findFirstElementOrCreate( 'vsys-container', $vsysEntryNode, $onPrem);

                $deviceOnPrem = $this->owner->findDeviceOnPrem( $onPrem );
                if( $deviceOnPrem !== null )
                    $tmp_obj->addReference( $deviceOnPrem );
            }
        }
        else
        {
            mwarning( "ManagedDevice with serial: ".$serial." is already available");
            return null;
        }

        return $tmp_obj;
    }

    /**
     * @param $serial
     * @param null $ref
     * @param bool $nested
     */
    public function removeManagedDevice($serial, $ref = null, $nested = TRUE)
    {
        $tmp_obj = $this->findByName($serial, $ref, $nested);

        if( $tmp_obj === null )
        {
            mwarning( "ManagedDevice with serial: ".$serial." is not available and can NOT be removed");
            return null;
        }
        else
        {
            unset( $this->owner->managedFirewallsSerials[$serial] );
            $this->remove( $tmp_obj );

            foreach( $this->xmlroot->childNodes as $device )
            {
                if( $device->nodeType != 1 ) continue;
                $devname = DH::findAttribute('name', $device);

                if( $devname === $serial )
                {
                    if( count($this->owner->managedFirewallsSerials) > 0 )
                        DH::removeChild( $this->xmlroot, $device );
                    else
                        DH::clearDomNodeChilds($this->xmlroot);

                    return true;
                }
            }
        }
    }
}