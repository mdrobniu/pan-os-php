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

class Template
{
    use ReferenceableObject;
    use PathableName;
    use PanSubHelperTrait;
    use XmlConvertible;

    /** @var PanoramaConf */
    public $owner;


    /** @var  PANConf */
    public $deviceConfiguration;

    protected $FirewallsSerials = array();

    /** @var CertificateStore */
    public $certificateStore = null;

    /**
     * Template constructor.
     * @param string $name
     * @param PanoramaConf $owner
     */
    public function __construct($name, $owner)
    {
        $this->name = $name;
        $this->owner = $owner;
        $this->deviceConfiguration = new PANConf(null, null, $this);

        $this->certificateStore = new CertificateStore($this);
        $this->certificateStore->setName('certificateStore');
    }

    public function load_from_domxml(DOMElement $xml)
    {
        $this->xmlroot = $xml;

        $this->name = DH::findAttribute('name', $xml);
        if( $this->name === FALSE )
            derr("template name not found\n", $xml);

        $tmp = DH::findFirstElementOrCreate('config', $xml);

        $this->deviceConfiguration->load_from_domxml($tmp);

        if( $this->owner->version <= 80 )
        {
            $xml = DH::findFirstElement('devices', $this->xmlroot);
            if( $xml !== false )
            {
                $this->FirewallsSerials = $this->owner->managedFirewallsStore->get_serial_from_xml($xml);
                foreach( $this->FirewallsSerials as $serial )
                {
                    $managedFirewall = $this->owner->managedFirewallsStore->find($serial);
                    if( $managedFirewall !== null )
                    {
                        $managedFirewall->addTemplate($this->name);
                        $managedFirewall->addReference( $this );
                    }

                }
            }
        }

        $shared = DH::findFirstElement('shared', $tmp);
        if( $shared !== false )
        {
            //
            // Extract Certificate objects
            //
            $tmp = DH::findFirstElement('certificate', $shared);
            if( $tmp !== FALSE )
            {
                $this->certificateStore->load_from_domxml($tmp);
            }
            // End of Certificate objects extraction
        }
    }

    public function name()
    {
        return $this->name;
    }

    //Todo: setName();
    //Problem is that this Template part is never used and can not written in XML yet, how to continue????
    //or can it????
    public function setName($newName)
    {
        $this->xmlroot->setAttribute('name', $newName);

        $this->name = $newName;
    }

    public function &getXPath()
    {
        $str = "/config/devices/entry[@name='localhost.localdomain']/template/entry[@name='" . $this->name . "']";

        return $str;
    }

    public function isTemplate()
    {
        return TRUE;
    }



    public function load_from_templateXml()
    {
        if( $this->owner === null )
            derr('cannot be used if owner === null');

        $fragment = $this->owner->xmlroot->ownerDocument->createDocumentFragment();

        if( !$fragment->appendXML(self::$templatexml) )
            derr('error occured while loading TEMPLATE template xml');

        $element = $this->owner->templateroot->appendChild($fragment);

        $this->load_from_domxml($element);
    }

    public function createVsys( $vsysName )
    {
        #<config><devices><entry name="localhost.localdomain"><vsys><entry name="vsys3">
        $settings_node = DH::findFirstElement('settings', $this->xmlroot);
        if($settings_node === FALSE)
        {
            $settings_node = DH::findFirstElementOrCreate('settings', $this->xmlroot);
            $default_node = DH::findFirstElementOrCreate('default-vsys', $settings_node);
            $default_node->textContent = $vsysName;
        }

        $config_node = DH::findFirstElementOrCreate('config', $this->xmlroot);
        $devices_node = DH::findFirstElementOrCreate('devices', $config_node);
        $entry_node = DH::findFirstElementByNameAttrOrCreate("entry","localhost.localdomain", $devices_node, $this->xmlroot->ownerDocument);
        $vsys_node = DH::findFirstElementOrCreate('vsys', $entry_node);
        $vsysName_node = DH::findFirstElementByNameAttrOrCreate("entry", $vsysName, $vsys_node, $this->xmlroot->ownerDocument);

    }

    public static $templatexml = '<entry name="**Need a Name**">
                                        <settings><default-vsys>vsys1</default-vsys></settings>
                                        <config><devices>
                                            <entry name="localhost.localdomain"><vsys><entry name="vsys1"/></vsys></entry>
                                        </devices></config>
									</entry>';

    public static $templateVSYSxml = '<entry name="**Need a Name**"/>';


}

