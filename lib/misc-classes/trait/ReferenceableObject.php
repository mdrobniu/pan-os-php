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

trait ReferenceableObject
{

    protected $name;
    public $refrules = array();
    protected $refcomphash = null;

    /**
     * @param string $newName
     * @param bool $skip_name_unicity_check
     * @return bool
     * @throws Exception
     */
    protected function setRefName($newName, $skip_name_unicity_check = FALSE)
    {
        if( !is_string($newName) )
            derr('$newName must be a string');

        if( $this->name == $newName )
            return FALSE;

        $oldName = $this->name;
        $this->name = $newName;

        $this->broadcastMyNameChange($oldName);

        return TRUE;
    }

    public function addReference($ref)
    {
        if( $ref === null )
            return;

        $serial = spl_object_hash($ref);

        if( isset($this->refrules[$serial]) )
            return;

        $this->refrules[$serial] = $ref;
        $this->refcomphash = null;
    }

    public function removeReference($ref)
    {
        if( $ref === null )
            return;

        $serial = spl_object_hash($ref);

        if( isset($this->refrules[$serial]) )
        {
            unset($this->refrules[$serial]);
            $this->refcomphash = null;
            return;
        }

        mwarning('tried to unreference an object that is not referenced:' . $this->toString() . '  against  ' . $ref->toString(), null, False);
    }

    public function broadcastMyNameChange($oldname)
    {

        foreach( $this->refrules as $ref )
        {
            #print get_class($ref)."\n";
            $ref->referencedObjectRenamed($this, $oldname);
        }

        if( $this->owner !== null )
        {
            $this->owner->referencedObjectRenamed($this, $oldname);
        }
    }

    public function replaceMeGlobally($newobject, $apimode=false)
    {
        foreach( $this->refrules as $o )
        {
            if( $apimode )
                $o->API_replaceReferencedObject($this, $newobject);
            else
                $o->replaceReferencedObject($this, $newobject);
        }
    }

    /**
     * @param $objectToAdd Service|ServiceGroup
     * @param $displayOutput bool
     * @param $skipIfConflict bool
     * @param $outputPadding string|int
     */
    public function addObjectWhereIamUsed($objectToAdd, $displayOutput = FALSE, $outputPadding = '', $skipIfConflict = FALSE)
    {
        derr('not implemented yet');
    }

    /**
     * @param $objectToAdd Service|ServiceGroup
     * @param $displayOutput bool
     * @param $skipIfConflict bool
     * @param $outputPadding string|int
     */
    public function API_addObjectWhereIamUsed($objectToAdd, $displayOutput = FALSE, $outputPadding = '', $skipIfConflict = FALSE)
    {
        derr('not implemented yet');
    }


    public function countReferences()
    {
        return count($this->refrules);
    }

    public function countLocationReferences()
    {
        $refLocationcounter = array();
        $this->getReferencesLocation($refLocationcounter);
        return count($refLocationcounter);
    }

    public function display_references($indent = 0)
    {
        $strpad = str_pad('', $indent);
        PH::print_stdout( $strpad . "* Displaying referencers for " . $this->toString() );
        foreach( $this->refrules as $o )
        {
            PH::print_stdout( $strpad . '  - ' . $o->toString() );
        }
    }

    /**
     * @return SecurityRule[]
     */
    public function findAssociatedSecurityRules()
    {
        return $this->findAssociatedRule_byType('SecurityRule');
    }

    /**
     * @param string $type
     * @return Rule[]
     */
    public function findAssociatedRule_byType($type)
    {
        $ret = array();

        foreach( $this->refrules as $cur )
        {
            if( isset($cur->owner) && $cur->owner !== null )
            {
                $class = get_class($cur->owner);

                if( $class == $type )
                {
                    if( !in_array($cur->owner, $ret, TRUE) )
                    {
                        $ret[] = $cur->owner;
                    }
                }

            }
        }

        return $ret;
    }


    public function generateRefHashComp($force = FALSE)
    {
        if( $this->refcomphash !== null && !$force )
            return;

        $fasthashcomp = 'ReferenceableObject';

        $tmpa = $this->refrules;

        usort($tmpa, "__CmpObjMemID");

        foreach( $tmpa as $o )
        {
            $fasthashcomp .= '.*/' . spl_object_hash($o);
        }

        $this->refcomphash = md5($fasthashcomp, TRUE);

    }

    public function getRefHashComp()
    {
        $this->generateRefHashComp();
        return $this->refcomphash;
    }

    public function getReferences()
    {
        return $this->refrules;
    }

    public function getReferencesRecursive( $references = array() )
    {
        //Todo: problem if same objects groups are available multiple times with different members;
        //check location or something, no clue yet 20211115
        if( get_class( $this ) == "Address" || get_class( $this ) == "AddressGroup" )
        {
            foreach( $this->refrules as $reference )
            {
                if( get_class( $reference ) == "Address" || get_class( $reference ) == "AddressGroup" )
                {
                    $recursiveReferences = $reference->getReferencesRecursive();
                    $references = array_merge( $references, $recursiveReferences );
                }
            }
            $references = array_merge( $references, $this->refrules );
        }

        return $references;
    }

    public function getReferencesLocation( &$counter_array = array() )
    {
        $location_array = array();
        foreach( $this->refrules as $cur )
        {
            #print "--------------\n";
            #print get_class( $cur)."\n";
            #print $cur->name()."\n";
            if( isset($cur->owner->owner->owner->owner) && get_class( $cur->owner->owner->owner->owner ) == "PanoramaConf" )
            {
                //Panorama - Rule
                if( isset($cur->owner->owner->owner) && $cur->owner->owner->owner !== null && method_exists($cur->owner->owner->owner,'name') && $cur->owner->owner->owner->name() !== "")
                {
                    #print "pan | ".$cur->owner->owner->owner->name()."\n";
                    $location_array[$cur->owner->owner->owner->name()] = $cur->owner->owner->owner->name();
                    if( isset($counter_array[$cur->owner->owner->owner->name()]))
                        $counter_array[$cur->owner->owner->owner->name()] += 1;
                    else
                        $counter_array[$cur->owner->owner->owner->name()] = 1;
                }
            }
            elseif( isset($cur->owner->owner->owner) && get_class( $cur->owner->owner->owner ) == "PanoramaConf" )
            {
                #Panorama - AddressGroup
                if( isset($cur->owner->owner) && $cur->owner->owner !== null && method_exists($cur->owner->owner,'name') && $cur->owner->owner->name() !== "")
                {
                    #print "pan-fw | ".$cur->owner->owner->name()."\n";
                    $location_array[$cur->owner->owner->name()] = $cur->owner->owner->name();
                    if( isset($counter_array[$cur->owner->owner->name()]))
                        $counter_array[$cur->owner->owner->name()] += 1;
                    else
                        $counter_array[$cur->owner->owner->name()] = 1;
                }
            }
            else
            {
                //possible to be on FW?????
                if( isset($cur->owner->owner->owner) && $cur->owner->owner->owner !== null && method_exists($cur->owner->owner->owner,'name') && $cur->owner->owner->owner->name() !== "")
                {
                    #print "fw-pan | ".$cur->owner->owner->owner->name()."\n";
                    $location_array[$cur->owner->owner->owner->name()] = $cur->owner->owner->owner->name();
                    if( isset($counter_array[$cur->owner->owner->owner->name()]))
                        $counter_array[$cur->owner->owner->owner->name()] += 1;
                    else
                        $counter_array[$cur->owner->owner->owner->name()] = 1;
                }
                //Firewall
                elseif( isset($cur->owner->owner) && $cur->owner->owner !== null && method_exists($cur->owner->owner,'name') && $cur->owner->owner->name() !== "")
                {
                    #print "fw | ".$cur->owner->owner->name()."\n";
                    $location_array[$cur->owner->owner->name()] = $cur->owner->owner->name();
                    if( isset($counter_array[$cur->owner->owner->name()]))
                        $counter_array[$cur->owner->owner->name()] += 1;
                    else
                        $counter_array[$cur->owner->owner->name()] = 1;
                }
            }


            if( get_class( $cur ) == "AddressGroup" || get_class( $cur ) == "ServiceGroup"  )
            {
                //why is this needed
                    ##print $cur->name()."\n";
                #$recursive_loc_array = $cur->getReferencesLocation( );
                    ##print_r( $recursive_loc_array );
                #$location_array = array_merge( $location_array, $recursive_loc_array );
            }
        }
        #print_r($location_array);
        return $location_array;
    }

    public function getReferencesLocationType( &$counter_array = array() )
    {
        $location_array = array();
        foreach( $this->refrules as $cur )
        {
            $debug = false;
            if( $debug )
            {
                print "--------------\n";
                print get_class( $cur)."\n";
                print $cur->name()."\n";
                if( isset($cur->owner->owner->owner->owner))
                    print "owner:". get_class( $cur->owner->owner->owner->owner )."\n";
            }
            if( isset($cur->owner->owner->owner->owner) && get_class( $cur->owner->owner->owner->owner ) == "PanoramaConf" )
            {
                //Panorama - Rule
                if( isset($cur->owner->owner->owner) && $cur->owner->owner->owner !== null
                    && method_exists($cur->owner->owner->owner,'name') && $cur->owner->owner->owner->name() !== "")
                {
                    if( $debug )
                        print "pan | ".$cur->owner->owner->owner->name()."\n";
                    $getClassName = get_class($cur->owner->owner->owner);
                    $location_array[ $getClassName] = $getClassName;
                    if( isset($counter_array[ $getClassName ]))
                        $counter_array[ $getClassName ] += 1;
                    else
                        $counter_array[ $getClassName ] = 1;
                }
            }
            elseif( isset($cur->owner->owner->owner->owner) && ( get_class( $cur->owner->owner->owner->owner ) == "TemplateStack" || get_class( $cur->owner->owner->owner->owner ) == "Template" ) )
            {
                //Panorama - Rule
                if( isset($cur->owner->owner->owner->owner) && $cur->owner->owner->owner->owner !== null
                    && method_exists($cur->owner->owner->owner->owner,'name') && $cur->owner->owner->owner->owner->name() !== "")
                {
                    if( $debug )
                        print "pan | ".$cur->owner->owner->owner->name()."\n";
                    $getClassName = get_class($cur->owner->owner->owner->owner);
                    $location_array[ $getClassName] = $getClassName;
                    if( isset($counter_array[ $getClassName ]))
                        $counter_array[ $getClassName ] += 1;
                    else
                        $counter_array[ $getClassName ] = 1;
                }
            }
            elseif( isset($cur->owner->owner->owner) && get_class( $cur->owner->owner->owner ) == "PanoramaConf" )
            {
                #Panorama - AddressGroup
                if( isset($cur->owner->owner) && $cur->owner->owner !== null && method_exists($cur->owner->owner,'name') && $cur->owner->owner->name() !== "")
                {
                    if( $debug )
                        print "pan-fw | ".$cur->owner->owner->name()."\n";
                    $getClassName = get_class($cur->owner->owner);
                    $location_array[ $getClassName ] = $getClassName;
                    if( isset($counter_array[ $getClassName ]))
                        $counter_array[ $getClassName ] += 1;
                    else
                        $counter_array[ $getClassName ] = 1;
                }
            }
            else
            {
                //possible to be on FW?????
                if( isset($cur->owner->owner->owner) && $cur->owner->owner->owner !== null
                    && method_exists($cur->owner->owner->owner,'name') && $cur->owner->owner->owner->name() !== "")
                {
                    if( $debug )
                        print "fw-pan | ".$cur->owner->owner->owner->name()."\n";
                    $getClassName = get_class($cur->owner->owner->owner);
                    $location_array[ $getClassName ] = $getClassName;
                    if( isset($counter_array[ $getClassName ]))
                        $counter_array[ $getClassName ] += 1;
                    else
                        $counter_array[ $getClassName ] = 1;
                }
                //Firewall
                elseif( isset($cur->owner->owner) && $cur->owner->owner !== null && method_exists($cur->owner->owner,'name') && $cur->owner->owner->name() !== "")
                {
                    if( $debug )
                        print "fw | ".$cur->owner->owner->name()."\n";
                    $getClassName = get_class($cur->owner->owner);
                    $location_array[ $getClassName ] = $getClassName;
                    if( isset($counter_array[ $getClassName ]))
                        $counter_array[ $getClassName ] += 1;
                    else
                        $counter_array[ $getClassName ] = 1;
                }
            }


            if( get_class( $cur ) == "AddressGroup" || get_class( $cur ) == "ServiceGroup"  )
            {
                //why is this needed
                ##print $cur->name()."\n";
                #$recursive_loc_array = $cur->getReferencesLocation( );
                ##print_r( $recursive_loc_array );
                #$location_array = array_merge( $location_array, $recursive_loc_array );
            }
        }
        #print_r($location_array);
        return $location_array;
    }

    public function getReferencesStore()
    {
        $store_array = array();
        foreach( $this->refrules as $cur )
        {
            if( isset($cur->owner->owner) && $cur->owner->owner !== null )
            {
                $class = get_class($cur->owner->owner);
                if( $class == "PanoramaConf" || $class == "PANConf"
                    || $class == "DeviceGroup" || $class == "VirtualSystem"
                    || $class == "BuckbeakConf" || $class == "FawkesConf"
                    || $class == "Container" || $class == "DeviceCloud" || $class == "DeviceOnPrem" || $class == "Snippet"
                )
                    $class = get_class($cur->owner);
                $class = strtolower($class);
                $store_array[$class] = $class;
            }
        }
        return $store_array;
    }

    /**
     * @param string $value
     */
    public function ReferencesStoreValidation($value)
    {
        $store_array = array();
        $store_array['addressstore'] = FALSE;
        $store_array['servicestore'] = FALSE;
        $store_array['rulestore'] = FALSE;
        $store_array['securityprofilegroupstore'] = FALSE;

        $store_array['logicalrouterstore'] = FALSE;
        $store_array['virtualrouterstore'] = FALSE;
        $store_array['ethernetifstore'] = FALSE;
        $store_array['tunnelifstore'] = FALSE;
        $store_array['gpgatewaystore'] = FALSE;
        $store_array['gpportalstore'] = FALSE;
        $store_array['ikegatewaystore'] = FALSE;
        $store_array['gretunnelstore'] = FALSE;
        $store_array['loopbackifstore'] = FALSE;
        $store_array['vlanifstore'] = FALSE;
        $store_array['zonestore'] = FALSE;


        if( !array_key_exists($value, $store_array) )
        {
            $store_string = "";
            $first = TRUE;
            foreach( array_keys($store_array) as $storeName )
            {
                if( $first )
                {
                    $store_string .= "'" . $storeName . "'";
                    $first = FALSE;
                }
                else
                    $store_string .= ", '" . $storeName . "'";
            }

            derr("this is not a store name: '" . $value . "' | possible names: " . $store_string . "\n");
        }
    }

    public function getReferencesType()
    {
        $type_array = array();
        foreach( $this->refrules as $cur )
        {
            if( isset($cur->owner) && $cur->owner !== null )
            {
                if( get_class($cur) == "URLProfile" )
                    $class = get_class($cur);
                else
                    $class = get_class($cur->owner);
                $class = strtolower($class);
                $type_array[$class] = $class;
            }

        }
        return $type_array;
    }

    /**
     * @param string $value
     */
    public function ReferencesTypeValidation($value)
    {
        $type_array = array();
        $type_array['address'] = FALSE;
        $type_array['addressgroup'] = FALSE;
        $type_array['service'] = FALSE;
        $type_array['servicegroup'] = FALSE;
        $type_array['securityrule'] = FALSE;
        $type_array['natrule'] = FALSE;
        $type_array['decryptionrule'] = FALSE;
        $type_array['appoverriderule'] = FALSE;
        $type_array['captiveportalrule'] = FALSE;
        $type_array['authenticationrule'] = FALSE;
        $type_array['pbfrule'] = FALSE;
        $type_array['qosrule'] = FALSE;
        $type_array['dosrule'] = FALSE;

        $type_array['urlprofile'] = FALSE;

        if( !array_key_exists($value, $type_array) )
        {
            $type_string = "";
            $first = TRUE;
            foreach( array_keys($type_array) as $typeName )
            {
                if( $first )
                {
                    $type_string .= "'" . $typeName . "'";
                    $first = FALSE;
                }
                else
                    $type_string .= ", '" . $typeName . "'";
            }

            derr("this is not a type name: '" . $value . "' | possible names: " . $type_string . "\n");
        }
    }

    /**
     * @param string $className
     * @return array
     */
    public function & findReferencesWithClass($className)
    {
        $ret = array();

        foreach( $this->refrules as $reference )
        {
            if( get_class($reference) == $className )
                $ret[] = $reference;
        }

        return $ret;
    }

    /**
     * @param string $className
     * @return array
     */
    public function & findReferencesWithOwnerClass($className)
    {
        $ret = array();

        foreach( $this->refrules as $reference )
        {
            if( get_class($reference->owner) == $className )
                $ret[] = $reference;
        }

        return $ret;
    }

    public function name()
    {
        return $this->name;
    }

    public function objectIsUnused()
    {
        $className = "";

        if( get_class($this) == 'Service' || get_class($this) == 'ServiceGroup' )
            $className = 'ServiceGroup';
        elseif( get_class($this) == 'Address' || get_class($this) == 'AddressGroup' )
            $className = 'AddressGroup';
        else
            return null;

        /** @var Address|AddressGroup|Service|ServiceGroup $this */
        if( $this->countReferences() == 0 )
        {
            //- check if higher DG has same name and only if it is also unused return TRUE
            //Todo: 2024117 swaschkut - compare how it is done in MERGER class - findAncestor / findChildAncestor
            if( isset($this->owner->parentCentralStore) && $this->owner->parentCentralStore !== null )
            {
                $tmp_obj = $this->owner->parentCentralStore->find($this->name());
                if ($tmp_obj === null || $tmp_obj->countReferences() == 0)
                    return TRUE;
                else
                    return FALSE;
            }

            //Todo: check also childDg if same name is available,
            //for shared level, as this has no parentStore
            return TRUE;
        }

        return false;
    }

    public function objectIsUnusedRecursive()
    {
        $className = "";

        if( get_class($this) == 'Service' || get_class($this) == 'ServiceGroup' )
            $className = 'ServiceGroup';
        elseif( get_class($this) == 'Address' || get_class($this) == 'AddressGroup' )
            $className = 'AddressGroup';
        else
            return null;

        /** @var Address|AddressGroup|Service|ServiceGroup $this */
        if( $this->countReferences() == 0 )
        {
            //- check if higher DG has same name and only if it is also unused return TRUE
            //Todo: 2024117 swaschkut - compare how it is done in MERGER class - findAncestor / findChildAncestor
            if( isset($this->owner->parentCentralStore) && $this->owner->parentCentralStore !== null )
            {
                $tmp_obj = $this->owner->parentCentralStore->find($this->name());
                if( $tmp_obj === null || $tmp_obj->countReferences() == 0 )
                    return TRUE;
                else
                    return FALSE;
            }
        }

        $groups = $this->findReferencesWithClass($className);

        if( count($groups) != $this->countReferences() )
            return FALSE;

        if( count($groups) == 0 )
            return TRUE;

        foreach( $groups as $group )
        {
            /** @var ServiceGroup $group */
            if( $group->objectIsUnusedRecursive() == FALSE )
                return FALSE;
        }

        return TRUE;
    }
}
