<?php
/**
 * ISC License
 *
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

class MERGER extends UTIL
{
    public $utilType = null;
    public $location_array = array();
    public $pickFilter = null;
    public $excludeFilter = null;
    public $upperLevelSearch = FALSE;
    public $mergeCountLimit = FALSE;
    public $dupAlg = null;

    public $deletedObjects = array();
    public $skippedObjects = array();

    public $addMissingObjects = FALSE;
    public $action = "merge";
    public $mergermodeghost = TRUE;

    public $exportcsv = FALSE;
    public $exportcsvFile = null;
    public $exportcsvSkippedFile = null;

    public bool $allowMergingObjectWith_m32 = true;

    public function utilStart()
    {
        $this->usageMsg = PH::boldText('USAGE: ') . "php " . basename(__FILE__) . " in=inputfile.xml [out=outputfile.xml] location=shared [DupAlgorithm=XYZ] [MergeCountLimit=100] ['pickFilter=(name regex /^H-/)'] ...";


        $this->add_supported_arguments();


        $this->prepareSupportedArgumentsArray();

        PH::processCliArgs();

        $this->arg_validation();

        $this->listfilters();

        if( isset(PH::$args['actions']) )
        {
            $this->action = PH::$args['actions'];

            if( $this->action !== 'merge' && $this->action !== 'display' && $this->action !== 'mergenoghost' )
                derr( 'argument actions only support value: merge / display / mergenoghost | actions=merge' );

            if( $this->action === 'mergenoghost' )
            {
                $this->action = "merge";
                $this->mergermodeghost = FALSE;
            }
        }
        else
            $this->action = "merge";

        if( isset(PH::$args['projectfolder']) )
        {
            $this->projectFolder = PH::$args['projectfolder'];
            if (!file_exists($this->projectFolder)) {
                mkdir($this->projectFolder, 0777, true);
            }
        }

        if( isset(PH::$args['outputformatset']) )
        {
            $this->outputformatset = TRUE;

            if( !is_bool(PH::$args['outputformatset']) )
            {
                $this->outputformatsetFile = PH::$args['outputformatset'];

                if( $this->projectFolder !== null )
                    $this->outputformatsetFile = $this->projectFolder."/".$this->outputformatsetFile;
            }
            else
                $this->outputformatsetFile = null;
        }

        if( isset(PH::$args['exportcsv']) )
        {
            $this->exportcsv = TRUE;
            $this->exportcsvFile = PH::$args['exportcsv'];
            $this->exportcsvSkippedFile = "skipped-".$this->exportcsvFile;

            if( $this->projectFolder !== null )
            {
                $this->exportcsvSkippedFile = $this->projectFolder."/skipped-".$this->exportcsvFile;
                $this->exportcsvFile = $this->projectFolder."/".$this->exportcsvFile;
            }

        }

        if( isset(PH::$args['outputformatset']) )
        {
            PH::print_stdout(" set 'origXmlDoc' variable");
            $this->outputformatset = TRUE;
            $this->origXmlDoc = new DOMDocument();

            if( !is_bool(PH::$args['outputformatset']) )
            {
                $this->outputformatsetFile = PH::$args['outputformatset'];

                if( $this->projectFolder !== null )
                {
                    if( strpos($this->outputformatsetFile, $this->projectFolder) === FALSE )
                        $this->outputformatsetFile = $this->projectFolder."/".$this->outputformatsetFile;
                }

            }
        }

        $this->help(PH::$args);
        $this->inDebugapiArgument();
        $this->inputValidation();
        $this->location_provided();

        $this->load_config();

        $this->location_filter();

        $this->location_array = $this->merger_location_array($this->utilType, $this->objectsLocation, $this->pan);


        $this->filterArgument( );


        $this->merger_arguments( );

        if( $this->action === "display" )
        {
            $this->apiMode = FALSE;
            $this->action = "merge";
        }

        if( $this->utilType == "address-merger" )
            $this->address_merging();
        elseif( $this->utilType == "addressgroup-merger" )
            $this->addressgroup_merging();
        elseif( $this->utilType == "service-merger" )
            $this->service_merging();
        elseif( $this->utilType == "servicegroup-merger" )
            $this->servicegroup_merging();
        elseif( $this->utilType == "tag-merger" )
            $this->tag_merging();
        elseif( $this->utilType == "custom-url-category-merger" )
        {
            $this->custom_url_category_merging();
        }
        #elseif( $this->utilType == "object-merger" )
        #    $this->object_merging( "Application" );

        $this->merger_final_step();

    }

    function merger_location_array($utilType, $objectsLocation, $pan)
    {
        $this->utilType = $utilType;

        if( $objectsLocation[0] == 'any' )
        {
            if( $pan->isPanorama() )
            {
                $alldevicegroup = $pan->deviceGroups;
            }
            elseif( $pan->isFawkes() || $pan->isBuckbeak() )
            {
                $subGroups = $pan->getContainers();
                $subGroups2 = $pan->getDeviceClouds();

                $alldevicegroup = array_merge( $subGroups, $subGroups2 );

                $subGroups2 = $this->pan->getDeviceOnPrems();
                $alldevicegroup = array_merge( $alldevicegroup, $subGroups2 );
            }
            elseif( $pan->isFirewall() )
                $alldevicegroup = $pan->virtualSystems;
            else
                $alldevicegroup = $pan->virtualSystems;

            $location_array = array();
            foreach( $alldevicegroup as $key => $tmp_location )
            {
                $objectsLocation = $tmp_location->name();
                $findLocation = $pan->findSubSystemByName($objectsLocation);
                if( $findLocation === null )
                    $this->locationNotFound( $objectsLocation );
                    #derr("cannot find DeviceGroup/VSYS named '{$objectsLocation}', check case or syntax");

                if( $this->utilType == "address-merger" || $this->utilType == "addressgroup-merger" )
                {
                    $store = $findLocation->addressStore;

                    if( $pan->isPanorama() && isset($findLocation->parentDeviceGroup) && $findLocation->parentDeviceGroup !== null )
                        $parentStore = $findLocation->parentDeviceGroup->addressStore;
                    elseif( ($pan->isFawkes() || $pan->isBuckbeak()) && isset($current->owner->parentContainer) && $current->owner->parentContainer !== null )
                        $parentStore = $findLocation->parentContainer->addressStore;
                    elseif( (!$pan->isFawkes() && !$pan->isBuckbeak()) )
                        $parentStore = $findLocation->owner->addressStore;
                }
                elseif( $this->utilType == "service-merger" || $this->utilType == "servicegroup-merger" )
                {
                    $store = $findLocation->serviceStore;

                    if( $pan->isPanorama() && isset($findLocation->parentDeviceGroup) && $findLocation->parentDeviceGroup !== null )
                        $parentStore = $findLocation->parentDeviceGroup->serviceStore;
                    elseif( ($pan->isFawkes() || $pan->isBuckbeak()) && isset($current->owner->parentContainer) && $current->owner->parentContainer !== null )
                        $parentStore = $findLocation->parentContainer->serviceStore;
                    elseif( (!$pan->isFawkes() && !$pan->isBuckbeak()) )
                        $parentStore = $findLocation->owner->serviceStore;
                }
                elseif( $this->utilType == "tag-merger" )
                {
                    $store = $findLocation->tagStore;

                    if( $pan->isPanorama() && isset($findLocation->parentDeviceGroup) && $findLocation->parentDeviceGroup !== null )
                        $parentStore = $findLocation->parentDeviceGroup->tagStore;
                    elseif( ($pan->isFawkes() || $pan->isBuckbeak()) && isset($current->owner->parentContainer) && $current->owner->parentContainer !== null )
                        $parentStore = $findLocation->parentContainer->tagStore;
                    elseif( (!$pan->isFawkes() && !$pan->isBuckbeak()) )
                        $parentStore = $findLocation->owner->tagStore;
                }
                elseif( $this->utilType == "custom-url-category-merger" )
                {
                    $store = $findLocation->customURLProfileStore;

                    if( $pan->isPanorama() && isset($findLocation->parentDeviceGroup) && $findLocation->parentDeviceGroup !== null )
                        $parentStore = $findLocation->parentDeviceGroup->customURLProfileStore;
                    elseif( ($pan->isFawkes() || $pan->isBuckbeak()) && isset($current->owner->parentContainer) && $current->owner->parentContainer !== null )
                        $parentStore = $findLocation->parentContainer->customURLProfileStore;
                    else
                        $parentStore = $findLocation->owner->customURLProfileStore;
                }

                if( get_class( $findLocation->owner ) == "FawkesConf" || get_class( $findLocation->owner ) == "BuckbeakConf" )
                    $parentStore = null;
                

                $location_array[$key]['findLocation'] = $findLocation;
                $location_array[$key]['store'] = $store;
                $location_array[$key]['parentStore'] = $parentStore;
                if( $pan->isPanorama() )
                {
                    $childDeviceGroups = $findLocation->childDeviceGroups(TRUE);
                    $location_array[$key]['childDeviceGroups'] = $childDeviceGroups;
                }
                elseif( ($pan->isFawkes() || $pan->isBuckbeak()) )
                {
                    //child Container/CloudDevices
                    //Todo: swaschkut 20210414
                    $location_array[$key]['childDeviceGroups'] = array();
                }
                else
                    $location_array[$key]['childDeviceGroups'] = array();

            }

            $location_array = array_reverse($location_array);

            if( !$pan->isFawkes() && !$pan->isBuckbeak() )
            {
                $location_array[$key + 1]['findLocation'] = 'shared';
                if( $this->utilType == "address-merger" || $this->utilType == "addressgroup-merger" )
                    $location_array[$key + 1]['store'] = $pan->addressStore;
                elseif( $this->utilType == "service-merger" || $this->utilType == "servicegroup-merger" )
                    $location_array[$key + 1]['store'] = $pan->serviceStore;
                elseif( $this->utilType == "tag-merger" )
                    $location_array[$key + 1]['store'] = $pan->tagStore;
                elseif( $this->utilType == "custom-url-category-merger" )
                    $location_array[$key + 1]['store'] = $pan->customURLProfileStore;

                $location_array[$key + 1]['parentStore'] = null;
                $location_array[$key + 1]['childDeviceGroups'] = $alldevicegroup;
            }
        }
        else
        {
            if( !$pan->isFawkes() && !$pan->isBuckbeak() )
            {
                $objectsLocations = $objectsLocation;
                #print "location count: ".count($objectsLocations);
                foreach( $objectsLocations as $key => $objectsLocation )
                {
                    if( $objectsLocation == "shared" )
                    {
                        if( $this->utilType == "address-merger" || $this->utilType == "addressgroup-merger" )
                            $store = $pan->addressStore;
                        elseif( $this->utilType == "service-merger" || $this->utilType == "servicegroup-merger" )
                            $store = $pan->serviceStore;
                        elseif( $this->utilType == "tag-merger" )
                            $store = $pan->tagStore;
                        elseif( $this->utilType == "custom-url-category-merger" )
                            $store = $pan->customURLProfileStore;

                        $parentStore = null;
                        $location_array[$key]['findLocation'] = $objectsLocation;
                        $location_array[$key]['store'] = $store;
                        $location_array[$key]['parentStore'] = $parentStore;
                    }
                    else
                    {
                        $findLocation = $pan->findSubSystemByName($objectsLocation);
                        if( $findLocation === null )
                            $this->locationNotFound($objectsLocation);

                        if( $this->utilType == "address-merger" || $this->utilType == "addressgroup-merger" )
                        {
                            $store = $findLocation->addressStore;

                            if( $pan->isPanorama() && isset($findLocation->parentDeviceGroup) && $findLocation->parentDeviceGroup !== null )
                                $parentStore = $findLocation->parentDeviceGroup->addressStore;
                            elseif( ($pan->isFawkes() || $pan->isBuckbeak()) && isset($current->owner->parentContainer) && $current->owner->parentContainer !== null )
                                $parentStore = $findLocation->parentContainer->addressStore;
                            else
                                $parentStore = $findLocation->owner->addressStore;
                        }
                        elseif( $this->utilType == "service-merger" || $this->utilType == "servicegroup-merger" )
                        {
                            $store = $findLocation->serviceStore;

                            if( $pan->isPanorama() && isset($findLocation->parentDeviceGroup) && $findLocation->parentDeviceGroup !== null )
                                $parentStore = $findLocation->parentDeviceGroup->serviceStore;
                            elseif( ($pan->isFawkes() || $pan->isBuckbeak()) && isset($current->owner->parentContainer) && $current->owner->parentContainer !== null )
                                $parentStore = $findLocation->parentContainer->serviceStore;
                            else
                                $parentStore = $findLocation->owner->serviceStore;
                        }
                        elseif( $this->utilType == "tag-merger" )
                        {
                            $store = $findLocation->tagStore;

                            if( $pan->isPanorama() && isset($findLocation->parentDeviceGroup) && $findLocation->parentDeviceGroup !== null )
                                $parentStore = $findLocation->parentDeviceGroup->tagStore;
                            elseif( ($pan->isFawkes() || $pan->isBuckbeak()) && isset($current->owner->parentContainer) && $current->owner->parentContainer !== null )
                                $parentStore = $findLocation->parentContainer->tagStore;
                            else
                                $parentStore = $findLocation->owner->tagStore;
                        }
                        elseif( $this->utilType == "custom-url-category-merger" )
                        {
                            $store = $findLocation->customURLProfileStore;

                            if( $pan->isPanorama() && isset($findLocation->parentDeviceGroup) && $findLocation->parentDeviceGroup !== null )
                                $parentStore = $findLocation->parentDeviceGroup->customURLProfileStore;
                            elseif( ($pan->isFawkes() || $pan->isBuckbeak()) && isset($current->owner->parentContainer) && $current->owner->parentContainer !== null )
                                $parentStore = $findLocation->parentContainer->customURLProfileStore;
                            else
                                $parentStore = $findLocation->owner->customURLProfileStore;
                        }
                        if( get_class($findLocation->owner) == "FawkesConf" )
                            $parentStore = null;

                        $location_array[$key]['findLocation'] = $findLocation;
                        $location_array[$key]['store'] = $store;
                        $location_array[$key]['parentStore'] = $parentStore;
                    }
                }
            }

            if( $pan->isPanorama() )
            {

                foreach( $this->objectsLocation as $key => $objectsLocation )
                {
                    if( $objectsLocation == 'shared' )
                        $childDeviceGroups = $pan->deviceGroups;
                    else
                        $childDeviceGroups = $findLocation->childDeviceGroups(TRUE);
                    $location_array[$key]['childDeviceGroups'] = $childDeviceGroups;
                }

            }
            elseif( $pan->isFawkes() || $pan->isBuckbeak() )
            {
                //child Container/CloudDevices
                //Todo: swaschkut 20210414
                foreach( $this->objectsLocation as $key => $objectsLocation )
                    $location_array[$key]['childDeviceGroups'] = array();
            }
            else
            {
                foreach( $this->objectsLocation as $key => $objectsLocation )
                    $location_array[$key]['childDeviceGroups'] = array();
            }

        }

        return $location_array;
    }


    function filterArgument( )
    {
        if( $this->utilType == "address-merger" || $this->utilType == "addressgroup-merger" )
            $type = 'address';
        elseif( $this->utilType == "service-merger" || $this->utilType == "servicegroup-merger" )
            $type = 'service';
        elseif( $this->utilType == "tag-merger" )
            $type = 'tag';
        elseif( $this->utilType == "custom-url-category-merger" )
            $type = 'customUrlProfile';

        if( isset(PH::$args['pickfilter']) )
        {
            $this->pickFilter = new RQuery($type);
            $errMsg = '';
            if( $this->pickFilter->parseFromString(PH::$args['pickfilter'], $errMsg) === FALSE )
                derr("invalid pickFilter was input: " . $errMsg);
            PH::print_stdout( " - pickFilter was input: " );
            $this->pickFilter->display();
            PH::print_stdout();
        }

        if( isset(PH::$args['excludefilter']) )
        {
            $this->excludeFilter = new RQuery($type);
            $errMsg = '';
            if( $this->excludeFilter->parseFromString(PH::$args['excludefilter'], $errMsg) === FALSE )
                derr("invalid pickFilter was input: " . $errMsg);
            PH::print_stdout( " - excludeFilter was input: " );
            $this->excludeFilter->display();
            PH::print_stdout();
        }

        if( isset(PH::$args['allowmergingwithupperlevel']) )
            $this->upperLevelSearch = TRUE;
    }

    function findAncestor( $current, $object, $StoreType = "addressStore" )
    {
        while( TRUE )
        {
            $findAncestor = $current->find($object->name(), null, TRUE);
            if( $findAncestor !== null )
            {
                return $findAncestor;
                break;
            }
            
            if( isset($current->owner->parentDeviceGroup) && $current->owner->parentDeviceGroup !== null )
                $current = $current->owner->parentDeviceGroup->$StoreType;
            elseif( isset($current->owner->parentContainer) && $current->owner->parentContainer !== null )
                $current = $current->owner->parentContainer->$StoreType;
            elseif( isset($current->owner->owner) && $current->owner->owner !== null && !$current->owner->owner->isFawkes() && !$current->owner->owner->isBuckbeak() )
                $current = $current->owner->owner->$StoreType;
            else
            {
                return null;
                break;
            }
        }
    }

    function findChildAncestor( $childDeviceGroups, $object, $StoreType= "addressStore" )
    {

        foreach( $childDeviceGroups as $deviceGroup )
        {
            $findAncestor = $deviceGroup->addressStore->find($object->name(), null, FALSE);
            if( $findAncestor !== null )
                return $findAncestor;
        }

        return null;
    }

    function add_supported_arguments()
    {
        $this->supportedArguments[] = array('niceName' => 'in', 'shortHelp' => 'input file ie: in=config.xml', 'argDesc' => '[filename]');
        $this->supportedArguments[] = array('niceName' => 'out', 'shortHelp' => 'output file to save config after changes. Only required when input is a file. ie: out=save-config.xml', 'argDesc' => '[filename]');
        $this->supportedArguments[] = array('niceName' => 'Location', 'shortHelp' => 'specify if you want to limit your query to a VSYS/DG. By default location=shared for Panorama, =vsys1 for PANOS', 'argDesc' => 'sys1|shared|dg1');

        $this->supportedArguments[] = array('niceName' => 'mergeCountLimit', 'shortHelp' => 'stop operations after X objects have been merged', 'argDesc' => '100');

        if( $this->utilType == "service-merger" )
        {
            $this->supportedArguments[] = array('niceName' => 'pickFilter',
                'shortHelp' => "specify a filter a pick which object will be kept while others will be replaced by this one.\n" .
                    "   ie: 2 services are found to be mergeable: 'H-1.1.1.1' and 'Server-ABC'. Then by using pickFilter=(name regex /^H-/) you would ensure that object H-1.1.1.1 would remain and Server-ABC be replaced by it.",
                'argDesc' => '(name regex /^g/)');
            $this->supportedArguments[] = array('niceName' => 'DupAlgorithm',
                'shortHelp' => "Specifies how to detect duplicates:\n" .
                    "  - SameDstSrcPorts: objects with same Dst and Src ports will be replaced by the one picked (default)\n" .
                    "  - SamePorts: objects with same Dst ports will be replaced by the one picked\n" .
                    "  - Identical: objects with same Dst and Src ports and same name will be replaced by the one picked\n" .
                    "  - WhereUsed: objects used exactly in the same location will be merged into 1 single object and all ports covered by these objects will be aggregated\n",
                'argDesc' => 'SameDstSrcPorts|SamePorts|Identical|WhereUsed');
        }
        else
            $this->supportedArguments[] = array('niceName' => 'pickFilter', 'shortHelp' => 'specify a filter a pick which object will be kept while others will be replaced by this one', 'argDesc' => '(name regex /^g/)');

        if( $this->utilType == "address-merger" )
        {
            $this->supportedArguments[] = array('niceName' => 'DupAlgorithm',
                'shortHelp' => "Specifies how to detect duplicates:\n" .
                    "  - SameAddress: objects with same Network-Value will be replaced by the one picked (default)\n" .
                    "  - Identical: objects with same network-value and same name will be replaced by the one picked\n" .
                    "  - WhereUsed: objects used exactly in the same location will be merged into 1 single object and all ports covered by these objects will be aggregated\n",
                'argDesc' => 'SameAddress|Identical|WhereUsed');
        }
        elseif( $this->utilType == "addressgroup-merger" )
        {
            $this->supportedArguments[] = array('niceName' => 'DupAlgorithm',
                'shortHelp' => "Specifies how to detect duplicates:\n" .
                    "  - SameMembers: groups holding same members replaced by the one picked first (default)\n" .
                    "  - SameIP4Mapping: groups resolving the same IP4 coverage will be replaced by the one picked first\n" .
                    "  - Identical: groups holding same members and same name will be replaced by the one picked\n" .
                    "  - WhereUsed: groups used exactly in the same location will be merged into 1 single groups with all members together\n",
                'argDesc' => 'SameMembers|SameIP4Mapping|Identical|WhereUsed');
        }
        elseif( $this->utilType == "servicegroup-merger" )
        {
            $this->supportedArguments[] = array('niceName' => 'DupAlgorithm',
                'shortHelp' => "Specifies how to detect duplicates:\n" .
                    "  - SameMembers: groups holding same members replaced by the one picked first (default)\n" .
                    "  - SamePortMapping: groups resolving the same port mapping coverage will be replaced by the one picked first\n" .
                    "  - Identical: groups holding same members and same name will be replaced by the one picked\n" .
                    "  - WhereUsed: groups used exactly in the same location will be merged into 1 single groups with all members together\n",
                'argDesc' => 'SameMembers|SamePortMapping|Identical|WhereUsed');
        }
        elseif( $this->utilType == "tag-merger" )
        {
            $this->supportedArguments[] = array('niceName' => 'DupAlgorithm',
                'shortHelp' => "Specifies how to detect duplicates:\n" .
                    "  - SameColor: objects with same TAG-color will be replaced by the one picked (default)\n" .
                    "  - Identical: objects with same TAG-color and same name will be replaced by the one picked\n" .
                    "  - WhereUsed: objects used exactly in the same location will be merged into 1 single object and all ports covered by these objects will be aggregated\n" .
                    "  - SameName: objects with same Name\n",
                'argDesc' => 'SameColor|Identical|WhereUsed|SameName');
        }
        elseif( $this->utilType == "custom-url-category-merger" )
        {
            $this->supportedArguments[] = array(
                'niceName' => 'DupAlgorithm',
                'shortHelp' => "Specifies how to detect duplicates:\n" .
                    "  - SameValue: objects with same URL will be replaced by the one picked (default)\n" .
                    "  - Identical: objects with same TAG-color and same name will be replaced by the one picked\n" .
                    "  - SameName: objects with same Name\n",
                'argDesc' => 'SameValue|Identical|SameName');
        }

        $this->supportedArguments[] = array('niceName' => 'excludeFilter', 'shortHelp' => 'specify a filter to exclude objects from merging process entirely', 'argDesc' => '(name regex /^g/)');
        $this->supportedArguments[] = array('niceName' => 'allowMergingWithUpperLevel', 'shortHelp' => 'when this argument is specified, it instructs the script to also look for duplicates in upper level');
        $this->supportedArguments[] = array('niceName' => 'allowaddingmissingobjects', 'shortHelp' => 'when this argument is specified, it instructs the script to also add missing objects for duplicates in upper level');
        $this->supportedArguments[] = array('niceName' => 'help', 'shortHelp' => 'this message');
        $this->supportedArguments[] = array('niceName' => 'DebugAPI', 'shortHelp' => 'prints API calls when they happen');

        $this->supportedArguments[] = array('niceName' => 'exportCSV', 'shortHelp' => 'when this argument is specified, it instructs the script to display the kept and removed objects per value');
    }

    function merger_arguments( )
    {
        $display_error = false;


        if( isset(PH::$args['mergecountlimit']) )
            $this->mergeCountLimit = PH::$args['mergecountlimit'];

        if( isset(PH::$args['dupalgorithm']) )
        {
            $this->dupAlg = strtolower(PH::$args['dupalgorithm']);
        }

        if( $this->utilType == "address-merger" )
        {
            if( $this->dupAlg != 'sameaddress' && $this->dupAlg != 'whereused' && $this->dupAlg != 'identical' )
                $display_error = true;

            $defaultDupAlg = 'sameaddress';
        }
        elseif( $this->utilType == "addressgroup-merger" )
        {
            if( $this->dupAlg != 'samemembers' && $this->dupAlg != 'sameip4mapping' && $this->dupAlg != 'identical' && $this->dupAlg != 'whereused' && $this->dupAlg != 'samename' )
                $display_error = true;

            if( isset(PH::$args['allowaddingmissingobjects']) )
                $this->addMissingObjects = TRUE;

            $defaultDupAlg = 'samemembers';
        }
        elseif( $this->utilType == "service-merger" )
        {
            if( $this->dupAlg != 'sameports' && $this->dupAlg != 'whereused' && $this->dupAlg != 'samedstsrcports' && $this->dupAlg != 'identical' )
                $display_error = true;

            $defaultDupAlg = 'samedstsrcports';
        }
        elseif( $this->utilType == "servicegroup-merger" )
        {
            if( $this->dupAlg != 'samemembers' && $this->dupAlg != 'sameportmapping' && $this->dupAlg != 'identical' && $this->dupAlg != 'whereused' )
                $display_error = true;

            if( isset(PH::$args['allowaddingmissingobjects']) )
                $this->addMissingObjects = TRUE;

            $defaultDupAlg = 'samemembers';
        }
        elseif( $this->utilType == "tag-merger" )
        {
            if( $this->dupAlg != 'samecolor' && $this->dupAlg != 'whereused' && $this->dupAlg != 'identical' && $this->dupAlg != 'samename' )
                $display_error = true;

            $defaultDupAlg = 'identical';
        }
        elseif( $this->utilType == "custom-url-category-merger" )
        {
            if( $this->dupAlg != 'samevalue' && $this->dupAlg != 'identical' && $this->dupAlg != 'samename' )
                $display_error = true;

            if( isset(PH::$args['allowaddingmissingobjects']) )
                $this->addMissingObjects = TRUE;

            $defaultDupAlg = 'samevalue';
        }
        /*
        elseif( $this->utilType == "object-merger" )
        {
            if( $this->dupAlg != 'identical' )
                $display_error = true;

            $defaultDupAlg = 'identical';
        }
        */


        if( isset(PH::$args['dupalgorithm']) )
        {
            #$this->dupAlg = strtolower(PH::$args['dupalgorithm']);
            if( $display_error )
                $this->display_error_usage_exit('unsupported value for dupAlgorithm: ' . PH::$args['dupalgorithm']);
        }
        else
            $this->dupAlg = $defaultDupAlg;

    }

    function locationSettings( $tmp_location, $type, &$store, &$findLocation, &$parentStore, &$childDeviceGroups)
    {
        $store = $tmp_location['store'];
        $findLocation = $tmp_location['findLocation'];
        $parentStore = $tmp_location['parentStore'];
        if( $this->upperLevelSearch )
            $childDeviceGroups = $tmp_location['childDeviceGroups'];
        else
            $childDeviceGroups = array();

        PH::print_stdout( "\n\n***********************************************\n" );
        PH::print_stdout( " - upper level search status : " . boolYesNo($this->upperLevelSearch) . "" );
        if( is_string($findLocation) )
            PH::print_stdout( " - location 'shared' found" );
        else
            PH::print_stdout( " - location '{$findLocation->name()}' found" );
        PH::print_stdout( " - found {$store->count()} ".$type." Objects" );
        PH::print_stdout( " - DupAlgorithm selected: {$this->dupAlg}" );
        PH::print_stdout( " - computing ".$type." values database ... " );
        sleep(1);
    }

    function addressgroup_merging()
    {
        foreach( $this->location_array as $tmp_location )
        {
            $store = null;
            $findLocation = null;
            $parentStore = null;
            $childDeviceGroups = null;
            $this->locationSettings( $tmp_location, "AddressGroup", $store, $findLocation,$parentStore,$childDeviceGroups);


            /**
             * @param AddressGroup $object
             * @return string
             */
            if( $this->dupAlg == 'samemembers' || $this->dupAlg == 'identical' )
                $hashGenerator = function ($object) {
                    /** @var AddressGroup $object */
                    $value = '';

                    $members = $object->members();
                    usort($members, '__CmpObjName');

                    foreach( $members as $member )
                    {
                        $value .= './.' . $member->name();
                    }

                    //$value = md5($value);

                    return $value;
                };
            elseif( $this->dupAlg == 'sameip4mapping' )
                $hashGenerator = function ($object) {
                    /** @var AddressGroup $object */
                    $value = '';

                    $mapping = $object->getFullMapping();

                    $value = $mapping['ip4']->dumpToString();

                    if( count($mapping['unresolved']) > 0 )
                    {
                        ksort($mapping['unresolved']);
                        $value .= '//unresolved:/';

                        foreach( $mapping['unresolved'] as $unresolvedEntry )
                            $value .= $unresolvedEntry->name() . '.%.';
                    }
                    //$value = md5($value);

                    return $value;
                };
            elseif( $this->dupAlg == 'whereused' )
                $hashGenerator = function ($object) {
                    if( $object->countReferences() == 0 )
                        return null;

                    /** @var AddressGroup $object */
                    $value = $object->getRefHashComp() . '//dynamic:' . boolYesNo($object->isDynamic());

                    return $value;
                };
            elseif( $this->dupAlg == 'samename' )
                $hashGenerator = function ($object) {
                    /** @var AddressGroup $object */
                    $value = '';

                    $name = $object->name();

                    return $name;
                };
            else
                derr("unsupported dupAlgorithm");

            //
            // Building a hash table of all address objects with same value
            //
            if( $this->upperLevelSearch )
                $objectsToSearchThrough = $store->nestedPointOfView();
            else
                $objectsToSearchThrough = $store->addressGroups();

            $hashMap = array();
            $NamehashMap = array();
            $child_hashMap = array();
            $child_NamehashMap = array();
            $upperHashMap = array();
            $upper_NamehashMap = array();

            //todo: childDG/childDG to parentDG merge is always done; should it not combined to upperLevelSearch value?
            foreach( $childDeviceGroups as $dg )
            {
                /** @var DeviceGroup $dg */
                foreach( $dg->addressStore->addressGroups() as $object )
                {
                    if( !$object->isGroup() || $object->isDynamic() )
                        continue;

                    if( $this->excludeFilter !== null && $this->excludeFilter->matchSingleObject(array('object' => $object, 'nestedQueries' => &$nestedQueries)) )
                        continue;

                    $value = $hashGenerator($object);
                    if( $value === null )
                        continue;

                    #PH::print_stdout( "add objNAME: " . $object->name() . " DG: " . $object->owner->owner->name() );
                    $child_hashMap[$value][] = $object;
                }
            }


            foreach( $objectsToSearchThrough as $object )
            {
                if( !$object->isGroup() || $object->isDynamic() )
                    continue;

                if( $this->excludeFilter !== null && $this->excludeFilter->matchSingleObject(array('object' => $object, 'nestedQueries' => &$nestedQueries)) )
                    continue;

                $skipThisOne = FALSE;

                // Object with descendants in lower device groups should be excluded
                if( $this->pan->isPanorama() && $object->owner === $store )
                {
                    //do something
                }
                elseif( ($this->pan->isFawkes() || $this->pan->isBuckbeak()) && $object->owner === $store )
                {
                    //do something
                }

                $value = $hashGenerator($object);
                if( $value === null )
                    continue;

                if( $object->owner === $store )
                {
                    $hashMap[$value][] = $object;
                    if( $parentStore !== null )
                        $object->ancestor = self::findAncestor( $parentStore, $object, "addressStore");

                    $object->childancestor = self::findChildAncestor( $childDeviceGroups, $object, "addressStore");
                }
                else
                    $upperHashMap[$value][] = $object;
            }

            //
            // Hashes with single entries have no duplicate, let's remove them
            //
            $countConcernedObjects = 0;
            foreach( $hashMap as $index => &$hash )
            {
                if( count($hash) == 1 && !isset($upperHashMap[$index]) && !isset(reset($hash)->ancestor) )
                {
                    //PH::print_stdout( "\nancestor not found for ".reset($hash)->name()."" );
                    unset($hashMap[$index]);
                }
                else
                    $countConcernedObjects += count($hash);
            }
            unset($hash);
            $countConcernedChildObjects = 0;
            foreach( $child_hashMap as $index => &$hash )
            {
                if( count($hash) == 1 && !isset($upperHashMap[$index]) && !isset(reset($hash)->ancestor) )
                    unset($child_hashMap[$index]);
                else
                    $countConcernedChildObjects += count($hash);
            }
            unset($hash);


            PH::print_stdout( " - found " . count($hashMap) . " duplicate values totalling {$countConcernedObjects} groups which are duplicate" );

            PH::print_stdout( " - found " . count($child_hashMap) . " duplicates childDG values totalling {$countConcernedChildObjects} address objects which are duplicate" );


            PH::print_stdout( "\n\nNow going after each duplicates for a replacement" );

            $countChildRemoved = 0;
            $countChildCreated = 0;
            foreach( $child_hashMap as $index => &$hash )
            {
                PH::print_stdout();
                PH::print_stdout( " - value '{$index}'" );


                $pickedObject = $this->PickObject( $hash );

                //Todo: swaschkut 20241124 bring in hash map validation as for other objects types

                $tmp_DG_name = $store->owner->name();
                if( $tmp_DG_name == "" )
                    $tmp_DG_name = 'shared';

                //this is an adddressgroup object
                $tmp_address = $store->find( $pickedObject->name() );
                if( $tmp_address == null && $this->dupAlg != "identical" )
                {
                    PH::print_stdout( "   * move object to DG: '".$tmp_DG_name."' : '".$pickedObject->name()."'" );

                    $skip = false;

                    //Todo: check all pickedObjects from hash
                    /*
                    $pickedObject_DG = $pickedObject->owner->owner;
                    if( $pickedObject_DG->parentDeviceGroup !== null )
                    {
                        $nextFindObject = $pickedObject_DG->parentDeviceGroup->addressStore->find( $pickedObject->name(), null, True );
                        if( $nextFindObject !== null )
                        {
                            if( $pickedObject->isAddress() && $nextFindObject->isAddress() )
                            {
                                if( $pickedObject->value() !== $nextFindObject->value() )
                                {
                                    PH::print_stdout("   * SKIPPED : this group has an object named '{$pickedObject->name()} that does exist in target location '{$tmp_DG_name}' with different value");
                                    $skip = TRUE;
                                    break;
                                }
                            }
                            elseif( $pickedObject->isGroup() && $nextFindObject->isGroup() )
                            {
                                $diff = $pickedObject->getValueDiff($nextFindObject);
                                if( count($diff['minus']) != 0 || count($diff['plus']) != 0 )
                                {
                                    PH::print_stdout("   * SKIPPED : this group has different membership compare to upperlevel");
                                    $skip = TRUE;
                                    break;
                                }
                            }
                            else
                            {
                                PH::print_stdout("   * SKIPPED : this group has an object named '{$pickedObject->name()} that does exist in target location '{$tmp_DG_name}' with different object type");
                                $skip = TRUE;
                                break;
                            }
                        }
                    }
                    */
                    $break = $this->checkParentPickObject( $hash );
                    if( $break )
                    {
                        PH::print_stdout("     this object can not be created" );
                        continue;
                    }

                    foreach( $pickedObject->members() as $memberObject )
                    {
                        /** @var Address|AddressGroup $memberObject */
                        $memberFound = $store->find($memberObject->name());
                        if( $memberFound === null )
                        {
                            if( $this->addMissingObjects )
                            {
                                //Todo: Swaschkut
                                PH::print_stdout("      - object: " . $memberObject->name() . " from DG: '" . $memberObject->owner->owner->name() . "' move to: '" . $tmp_DG_name . "'");
                                if( $this->action === "merge" )
                                {
                                    /** @var AddressStore $store */
                                    if( $this->apiMode )
                                    {
                                        $oldXpath = $memberObject->getXPath();
                                        $memberObject->owner->remove($memberObject);
                                        $store->add($memberObject);
                                        $memberObject->API_sync();
                                        $this->pan->connector->sendDeleteRequest($oldXpath);
                                    }
                                    else
                                    {
                                        $memberObject->owner->remove($memberObject);
                                        $store->add($memberObject);
                                    }
                                }
                            }
                            else
                            {
                                PH::print_stdout("   * SKIPPED : this group has an object named '{$memberObject->name()} that does not exist in target location '{$tmp_DG_name}'");
                                $skip = TRUE;
                                break;
                            }

                        }
                        else
                        {
                            /** @var Address|AddressGroup $memberFound */
                            if( $memberFound->isAddress() && $memberObject->isAddress() )
                            {
                                if( $memberFound->value() !== $memberObject->value() )
                                {
                                    PH::print_stdout("   * SKIPPED : this group has an object named '{$memberObject->name()} that does exist in target location '{$tmp_DG_name}' with different value");
                                    $skip = TRUE;
                                    break;
                                }
                            }
                            elseif( $memberFound->isGroup() && $memberObject->isGroup() )
                            {
                                $diff = $memberObject->getValueDiff($memberFound);
                                if( count($diff['minus']) != 0 || count($diff['plus']) != 0 )
                                {
                                    PH::print_stdout("   * SKIPPED : this group has different member ship compare to upperlevel");
                                    $skip = TRUE;
                                    break;
                                }
                            }
                            else
                            {
                                PH::print_stdout("   * SKIPPED : this group has an object named '{$memberObject->name()} that does exist in target location '{$tmp_DG_name}' with different object type");
                                $skip = TRUE;
                                break;
                            }

                        }
                    }


                    if( $skip )
                        continue;

                    if( $this->action === "merge" )
                    {
                        /** @var AddressStore $store */
                        if( $this->apiMode )
                        {
                            $oldXpath = $pickedObject->getXPath();
                            $pickedObject->owner->remove($pickedObject);
                            $store->add($pickedObject);
                            $pickedObject->API_sync();
                            $this->pan->connector->sendDeleteRequest($oldXpath);
                        }
                        else
                        {
                            $pickedObject->owner->remove($pickedObject);
                            $store->add($pickedObject);
                        }
                        $tmp_address = $store->find( $pickedObject->name() );
                        $value = $hashGenerator($tmp_address);
                        $hashMap[$value][] = $tmp_address;
                    }

                    $countChildCreated++;
                }
                elseif( $tmp_address === null )
                    continue;
                else
                {
                    if( !$tmp_address->isGroup() )
                    {
                        PH::print_stdout( "    - SKIP: object name '{$pickedObject->_PANC_shortName()}' of type ".get_class($pickedObject)." can not be merged with object name: '{$tmp_address->_PANC_shortName()}' of type ".get_class($tmp_address). " value: ".$tmp_address->value() );
                        $this->skippedObject( $index, $pickedObject, $tmp_address, get_class($pickedObject)." can not be merged with ".get_class($tmp_address));
                        continue;
                    }

                    $pickedObject_value = $hashGenerator($pickedObject);
                    $tmp_address_value = $hashGenerator($tmp_address);

                    if( $pickedObject_value == $tmp_address_value )
                    {
                        PH::print_stdout( "   * keeping object '{$tmp_address->_PANC_shortName()}'" );
                    }
                    else
                    {
                        if( $this->addMissingObjects )
                        {
                            $this->addressgroupGetValueDiff( $pickedObject, $tmp_address, true );
                        }
                        else
                        {
                            $stringSkippedReason = $pickedObject->displayValueDiff($tmp_address, 7, true);
                            PH::print_stdout( "    - SKIP: object name '{$pickedObject->_PANC_shortName()}' [with value '{$pickedObject_value}'] is not IDENTICAL to object name: '{$tmp_address->_PANC_shortName()}' [with value '{$tmp_address_value}']" );
                            $this->skippedObject( $index, $pickedObject, $tmp_address, $stringSkippedReason);
                            continue;
                        }
                    }
                }


                // Merging loop finally!
                foreach( $hash as $objectIndex => $object )
                {
                    if( $tmp_address === null )
                        continue;

                    //Todo: swaschkut 20241124 bring in hash map validation as for other objects types

                    if( isset( $object->childancestor ) )
                    {
                        $childancestor = $object->childancestor;

                        if( $childancestor !== null )
                        {
                            if( !$childancestor->isGroup() )
                            {
                                PH::print_stdout("    - SKIP: object name '{$object->_PANC_shortName()}' as one ancestor is of type: ". get_class( $childancestor )." '{$childancestor->_PANC_shortName()}' value: ".$childancestor->value());
                                $this->skippedObject( $index, $object, $childancestor, 'childancestor of type: '.get_class( $childancestor ));
                                break;
                            }

                            //Todo check ip4mapping of $childancestor and $object
                            /*
                            if( $hashGenerator($object) == $hashGenerator($ancestor) )
                            {
                                print "additional validation needed if same value\n";
                                break;
                            }
                            else
                            {

                            */
                                $this->addressgroupGetValueDiff($ancestor, $object, true);

                                if( isset($childancestor->owner) )
                                {
                                    $tmp_ancestor_DGname = $childancestor->owner->owner->name();
                                    if( $tmp_ancestor_DGname === "" )
                                        $tmp_ancestor_DGname = "shared";
                                }
                                else
                                    $tmp_ancestor_DGname = "shared";



                                PH::print_stdout("    - group '{$object->name()}' cannot be merged because it has an ancestor at DG: ".$tmp_ancestor_DGname );
                                PH::print_stdout( "    - ancestor type: ".get_class( $childancestor ) );
                                $this->skippedObject( $index, $object, $childancestor, 'childancestor at DG: '.$tmp_ancestor_DGname);

                                break;
                            //}
                        }
                    }

                    if( $this->dupAlg == 'identical' )
                        if( $object->name() != $tmp_address->name() )
                        {
                            PH::print_stdout("    - SKIP: object name '{$object->_PANC_shortName()}' is not IDENTICAL to object name from upperlevel '{$tmp_address->_PANC_shortName()}'");
                            $this->skippedObject( $index, $tmp_address, $object, 'name is not IDENTICAL');
                            continue;
                        }

                    if( $object !== $tmp_address )
                    {
                        PH::print_stdout("    - group '{$object->name()}' DG: '" . $object->owner->owner->name() . "' merged with its ancestor at DG: '" . $tmp_address->owner->owner->name() . "', deleting: " . $object->_PANC_shortName());

                        PH::print_stdout("    - replacing '{$object->_PANC_shortName()}' ...");
                        if( $this->action === "merge" )
                        {
                            $success = $object->__replaceWhereIamUsed($this->apiMode, $tmp_address, TRUE, 5);

                            if( $success )
                            {
                                if( $this->apiMode )
                                    $object->owner->API_remove($object, TRUE);
                                else
                                    $object->owner->remove($object, TRUE);

                                $countChildRemoved++;
                            }
                        }
                    }
                }
            }
            if( count( $child_hashMap ) > 0 )
                PH::print_stdout( "\n\nDuplicates ChildDG removal is now done. Number of objects after cleanup: '{$store->countAddressGroups()}' (removed/created {$countChildRemoved}/{$countChildCreated} addressgroups)\n" );


            $countRemoved = 0;
            foreach( $hashMap as $index => &$hash )
            {
                #$skip = false;

                PH::print_stdout();
                PH::print_stdout( " - value '{$index}'" );


                $pickedObject = $this->hashMapPickfilter( $upperHashMap, $index, $hash );

                //todo: swaschkut 20241119 validate if group object with same name is not available at lower level

                // Merging loop finally!
                foreach( $hash as $object )
                {
                    /** @var AddressGroup $object */

                    //Todo: swaschkut 20241124 bring in hash map validation as for other objects types

                    if( isset($object->ancestor) )
                    {
                        $ancestor = $object->ancestor;

                        if( !$ancestor->isGroup() )
                        {
                            PH::print_stdout("    - SKIP: object name '{$object->_PANC_shortName()}' as one ancestor is of type: ". get_class( $ancestor )." '{$ancestor->_PANC_shortName()}' value: ".$ancestor->value());
                            $this->skippedObject( $index, $object, $ancestor, 'ancestor of type: '.get_class( $ancestor ));
                            continue;
                        }

                        /** @var AddressGroup $ancestor */
                        if( $this->upperLevelSearch && $ancestor->isGroup() && !$ancestor->isDynamic() && $this->dupAlg != 'whereused' )
                        {
                            if( $this->dupAlg == 'identical' )
                                if( $object->name() != $ancestor->name() )
                                {
                                    PH::print_stdout("    - SKIP: object name '{$ancestor->_PANC_shortName()}' is not IDENTICAL to object name from upperlevel '{$object->_PANC_shortName()}'");
                                    $this->skippedObject( $index, $object, $ancestor, 'name is not IDENTICAL');
                                    continue;
                                }

                            if( $hashGenerator($object) != $hashGenerator($ancestor) )
                                $this->addressgroupGetValueDiff($ancestor, $object, true);

                            if( $hashGenerator($object) == $hashGenerator($ancestor) )
                            {
                                if( $this->dupAlg == 'samename' )
                                    $this->addressgroupGetValueDiff($ancestor, $object);

                                if( isset($ancestor->owner) )
                                {
                                    $tmp_ancestor_DGname = $ancestor->owner->owner->name();
                                    if( $tmp_ancestor_DGname === "" )
                                        $tmp_ancestor_DGname = "shared";
                                }
                                else
                                    $tmp_ancestor_DGname = "shared";

                                $text = "    - group '{$object->name()} DG: '" . $object->owner->owner->name() . "' merged with its ancestor at DG: '" . $tmp_ancestor_DGname . "', deleting: " . $object->_PANC_shortName();
                                self::deletedObject($index, $ancestor, $object);
                                if( $this->action === "merge" )
                                {
                                    $object->replaceMeGlobally($ancestor);
                                    if( $this->apiMode )
                                        $object->owner->API_remove($object, TRUE);
                                    else
                                        $object->owner->remove($object, TRUE);
                                }

                                PH::print_stdout($text);

                                if( $pickedObject === $object )
                                    $pickedObject = $ancestor;

                                $countRemoved++;
                                if( $this->mergeCountLimit !== FALSE && $countRemoved >= $this->mergeCountLimit )
                                {
                                    PH::print_stdout("\n *** STOPPING MERGE OPERATIONS NOW SINCE WE REACHED mergeCountLimit ({$this->mergeCountLimit})");
                                    break 2;
                                }
                                continue;
                            }
                        }

                        if( isset($ancestor->owner) )
                        {
                            $tmp_ancestor_DGname = $ancestor->owner->owner->name();
                            if( $tmp_ancestor_DGname === "" )
                                $tmp_ancestor_DGname = "shared";
                        }
                        else
                            $tmp_ancestor_DGname = "shared";


                        if( !$this->addMissingObjects )
                        {
                            PH::print_stdout("    - group '{$object->name()}' cannot be merged because it has an ancestor at DG: ".$tmp_ancestor_DGname );
                            PH::print_stdout( "    - ancestor type: ".get_class( $ancestor ) );
                            $this->skippedObject( $index, $object, $ancestor, 'ancestor at DG: '.$tmp_ancestor_DGname);
                            continue;
                        }

                    }

                    if( isset( $object->childancestor ) )
                    {
                        $childancestor = $object->childancestor;

                        if( $childancestor !== null )
                        {
                            if( !$childancestor->isGroup() )
                            {
                                PH::print_stdout("    - SKIP: object name '{$object->_PANC_shortName()}' as one ancestor is of type: ". get_class( $childancestor )." '{$childancestor->_PANC_shortName()}' value: ".$childancestor->value());
                                $this->skippedObject( $index, $object, $childancestor, 'childancestor of type: '.get_class( $childancestor ));
                                break;
                            }

                            //Todo check ip4mapping of $childancestor and $object
                            /*
                            if( $hashGenerator($object) == $hashGenerator($ancestor) )
                            {
                                print "additional validation needed if same value\n";
                                break;
                            }
                            else
                            {
                                */
                                $this->addressgroupGetValueDiff($childancestor, $object, true);

                                if( isset($childancestor->owner) )
                                {
                                    $tmp_ancestor_DGname = $childancestor->owner->owner->name();
                                    if( $tmp_ancestor_DGname === "" )
                                        $tmp_ancestor_DGname = "shared";
                                }
                                else
                                    $tmp_ancestor_DGname = "shared";



                                PH::print_stdout("    - group '{$object->name()}' cannot be merged because it has an ancestor at DG: ".$tmp_ancestor_DGname );
                                PH::print_stdout( "    - ancestor type: ".get_class( $childancestor ) );
                                $this->skippedObject( $index, $object, $childancestor, 'childancestor at DG: '.$tmp_ancestor_DGname);

                                break;
                            //}
                        }
                    }

                    if( $object === $pickedObject )
                    {
                        #PH::print_stdout("    - SKIPPED: '{$object->name()}' === '{$pickedObject->name()}': ");
                        continue;
                    }


                    if( $this->dupAlg == 'whereused' )
                    {
                        PH::print_stdout("    - merging '{$object->name()}' members into '{$pickedObject->name()}': ");
                        foreach( $object->members() as $member )
                        {
                            $text = "     - adding member '{$member->name()}'... ";
                            if( $this->action === "merge" )
                            {
                                if( $this->apiMode )
                                    $pickedObject->API_addMember($member);
                                else
                                    $pickedObject->addMember($member);
                            }
                            PH::print_stdout($text);
                        }
                        PH::print_stdout("    - now removing '{$object->name()} from where it's used");
                        $text = "    - deleting '{$object->name()}'... ";
                        self::deletedObject($index, $pickedObject, $object);
                        if( $this->action === "merge" )
                        {
                            if( $this->apiMode )
                            {
                                $object->API_removeWhereIamUsed(TRUE, 6);
                                $object->owner->API_remove($object);
                            }
                            else
                            {
                                $object->removeWhereIamUsed(TRUE, 6);
                                $object->owner->remove($object);
                            }
                        }
                        PH::print_stdout($text);
                    }
                    else
                    {
                        /*
                        if( $pickedObject->has( $object ) )
                        {
                            PH::print_stdout(  "   * SKIPPED : the pickedgroup {$pickedObject->_PANC_shortName()} has an object member named '{$object->_PANC_shortName()} that is planned to be replaced by this group" );
                            $skip = true;
                            continue;
                        }*/
                        if( $this->dupAlg == 'identical' )
                            if( $object->name() != $pickedObject->name() )
                            {
                                PH::print_stdout("    - SKIP: object name '{$pickedObject->_PANC_shortName()}' is not IDENTICAL to object name from upperlevel '{$object->_PANC_shortName()}'");
                                $this->skippedObject( $index, $object, $pickedObject, 'object name not IDENTICAL');
                                continue;
                            }

                        PH::print_stdout("    - replacing '{$object->_PANC_shortName()}' ...");
                        if( $this->action === "merge" )
                        {
                            $success = $object->__replaceWhereIamUsed($this->apiMode, $pickedObject, TRUE, 5);

                            if( $success )
                            {
                                PH::print_stdout("    - deleting '{$object->_PANC_shortName()}'");
                                self::deletedObject($index, $pickedObject, $object);
                                if( $this->apiMode )
                                    //true flag needed for nested groups in a specific constellation
                                    $object->owner->API_remove($object, TRUE);
                                else
                                    $object->owner->remove($object, TRUE);
                            }
                        }
                    }

                    #if( $skip )
                    #    continue;

                    $countRemoved++;

                    if( $this->mergeCountLimit !== FALSE && $countRemoved >= $this->mergeCountLimit )
                    {
                        PH::print_stdout("\n *** STOPPING MERGE OPERATIONS NOW SINCE WE REACHED mergeCountLimit ({$this->mergeCountLimit})");
                        break 2;
                    }
                }
            }

            PH::print_stdout( "\n\nDuplicates removal is now done. Number of objects after cleanup: '{$store->countAddressGroups()}' (removed {$countRemoved} groups)\n" );

        }    
    }

    function addressgroupGetValueDiff( $ancestor, $object, $display = false)
    {
        if( $display )
            $ancestor->displayValueDiff($object, 7);

        if( $this->addMissingObjects )
        {
            $diff = $ancestor->getValueDiff($object);
            $store = $ancestor->owner;
            if( count($diff['minus']) != 0 )
                foreach( $diff['minus'] as $d )
                {
                    /** @var Address|AddressGroup $d */

                    if( $store->find($d->name()) !== null )
                    {
                        $text = "      - adding objects to group: ";
                        $text .= $d->name();
                        PH::print_stdout($text);
                        if( $this->action === "merge" )
                        {
                            if( $this->apiMode )
                                $ancestor->API_addMember($d);
                            else
                                $ancestor->addMember($d);
                        }
                    }
                    else
                    {
                        #PH::print_stdout("      - object not found: " . $d->name() . "");
                        $text = "      - adding object: ";
                        $text .= $d->name();
                        $text .= " from DG '".$d->owner->owner->_PANC_shortName()."' to '".$store->owner->_PANC_shortName()."'";
                        $text .= " to group";
                        PH::print_stdout($text);
                        if( $this->action === "merge" )
                        {
                            /** @var AddressStore $store */
                            if( $this->apiMode )
                            {
                                $oldXpath = $d->getXPath();
                                $d->owner->remove($d);
                                $store->add($d);
                                $d->API_sync();
                                $this->pan->connector->sendDeleteRequest($d);
                            }
                            else
                            {
                                $d->owner->remove($d);
                                $store->add($d);
                            }
                        }
                    }
                }

            if( count($diff['plus']) != 0 )
                foreach( $diff['plus'] as $d )
                {
                    /** @var Address|AddressGroup $d */
                    //TMP usage to clean DG level ADDRESSgroup up
                    $object->addMember($d);
                }
        }
    }

    function address_merging()
    {
        foreach( $this->location_array as $tmp_location )
        {
            $store = null;
            $findLocation = null;
            $parentStore = null;
            $childDeviceGroups = null;
            $this->locationSettings( $tmp_location, "Address", $store, $findLocation,$parentStore,$childDeviceGroups);


//
// Building a hash table of all address objects with same value
//
            if( $this->upperLevelSearch )
                $objectsToSearchThrough = $store->nestedPointOfView();
            else
                $objectsToSearchThrough = $store->addressObjects();

            $hashMap = array();
            $NamehashMap = array();
            $child_hashMap = array();
            $child_NamehashMap = array();
            $upperHashMap = array();
            $upper_NamehashMap = array();
            if( $this->dupAlg == 'sameaddress' || $this->dupAlg == 'identical' )
            {
                //todo: childDG/childDG to parentDG merge is always done; should it not combined to upperLevelSearch value?
                foreach( $childDeviceGroups as $dg )
                {
                    foreach( $dg->addressStore->addressObjects() as $object )
                    {
                        if( !$object->isAddress() )
                            continue;
                        if( !$this->mergermodeghost && $object->isTmpAddr() )
                            continue;

                        if( $this->excludeFilter !== null && $this->excludeFilter->matchSingleObject(array('object' => $object, 'nestedQueries' => &$nestedQueries)) )
                            continue;

                        $value = $this->address_get_value_string( $object );

                        #PH::print_stdout( "add objNAME: " . $object->name() . " DG: " . $object->owner->owner->name() . "" );
                        $child_hashMap[$value][] = $object;
                        $child_NamehashMap[$object->name()][] = $object;
                    }
                }


                foreach( $objectsToSearchThrough as $object )
                {
                    if( !$object->isAddress() )
                        continue;
                    if( !$this->mergermodeghost && $object->isTmpAddr() )
                        continue;

                    if( $this->excludeFilter !== null && $this->excludeFilter->matchSingleObject(array('object' => $object, 'nestedQueries' => &$nestedQueries)) )
                        continue;

                    $skipThisOne = FALSE;

                    // Object with descendants in lower device groups should be excluded
                    if( $this->pan->isPanorama() && $object->owner === $store )
                    {
                        //do something
                    }
                    elseif( ($this->pan->isFawkes() || $this->pan->isBuckbeak()) && $object->owner === $store )
                    {
                        //do something
                    }

                    $value = $this->address_get_value_string( $object );

                    if( $object->owner === $store )
                    {
                        $hashMap[$value][] = $object;
                        $NamehashMap[$object->name()][] = $object;
                        if( $parentStore !== null )
                            $object->ancestor = self::findAncestor( $parentStore, $object, "addressStore" );

                        $object->childancestor = self::findChildAncestor( $childDeviceGroups, $object, "addressStore");
                    }
                    else
                    {
                        $upperHashMap[$value][] = $object;
                        $upper_NamehashMap[$object->name()][] = $object;
                    }

                }
            }
            elseif( $this->dupAlg == 'whereused' )
            {
                foreach( $objectsToSearchThrough as $object )
                {
                    if( !$object->isAddress() )
                        continue;
                    #if( $object->isTmpAddr() )
                    #    continue;

                    if( $object->countReferences() == 0 )
                        continue;

                    if( $this->excludeFilter !== null && $this->excludeFilter->matchSingleObject(array('object' => $object, 'nestedQueries' => &$nestedQueries)) )
                        continue;

                    $value = $this->address_get_value_string( $object );

                    if( $object->owner === $store )
                    {
                        $hashMap[$value][] = $object;
                        $NamehashMap[$object->name()][] = $object;
                        if( $parentStore !== null )
                            $object->ancestor = self::findAncestor( $parentStore, $object, "addressStore" );

                        $object->childancestor = self::findChildAncestor( $childDeviceGroups, $object, "addressStore" );
                    }
                    else
                    {
                        $upperHashMap[$value][] = $object;
                        $upper_NamehashMap[$object->name()][] = $object;
                    }

                }
            }
            else derr("unsupported use case");

//
// Hashes with single entries have no duplicate, let's remove them
//
            $countConcernedObjects = 0;
            self::removeSingleEntries( $hashMap, $child_hashMap, $upperHashMap, $countConcernedObjects);

            $countConcernedChildObjects = 0;
            self::removeSingleEntries( $child_hashMap, $hashMap, $upperHashMap, $countConcernedChildObjects);



            PH::print_stdout( " - found " . count($hashMap) . " duplicates values totalling {$countConcernedObjects} address objects which are duplicate" );

            PH::print_stdout( " - found " . count($child_hashMap) . " duplicates childDG values totalling {$countConcernedChildObjects} address objects which are duplicate" );


            PH::print_stdout( "\n\nNow going after each duplicates for a replacement" );

            $countChildRemoved = 0;
            $countChildCreated = 0;
            foreach( $child_hashMap as $index => &$hash )
            {
                PH::print_stdout();
                PH::print_stdout(" - value '{$index}'");

                $pickedObject = $this->PickObject( $hash );

                $checkHash = $this->address_service_hash_map_check( $index, $pickedObject, $NamehashMap, $upper_NamehashMap, $child_NamehashMap,true, true, true );
                if( !$checkHash )
                    continue;

                $tmp_DG_name = $store->owner->name();
                if( $tmp_DG_name == "" )
                    $tmp_DG_name = 'shared';

                $tmp_address = $store->find($pickedObject->name());
                if( $tmp_address == null && $this->dupAlg != 'identical'  )
                {
                    if( isset($child_NamehashMap[$pickedObject->name()]) )
                    {
                        $exit = FALSE;
                        $exitObject = null;
                        foreach( $child_NamehashMap[$pickedObject->name()] as $obj )
                        {
                            if( $obj === $pickedObject )
                                continue;

                            /** @var Address $obj */
                            /** @var Address $pickedObject */
                            if( (!$obj->isType_FQDN() && !$pickedObject->isType_FQDN()) && $obj->getNetworkMask() == '32' && $pickedObject->getNetworkMask() == '32' )
                            {
                                if( $this->allowMergingObjectWith_m32 && ($obj->getNetworkValue() == $pickedObject->getNetworkValue()) )
                                    $exit = FALSE;
                                else
                                {
                                    $exit = TRUE;
                                    $exitObject = $obj;
                                }
                            }
                            elseif( $obj->value() !== $pickedObject->value() )
                            {
                                $exit = TRUE;
                                $exitObject = $obj;
                            }

                            if( isset($obj->owner->parentCentralStore) )
                            {
                                $tmpParentStore = $obj->owner->parentCentralStore;
                                $tmp_obj = $tmpParentStore->find( $pickedObject->name(), null, true );
                                if( $tmp_obj !== null )
                                {
                                    if( (!$tmp_obj->isType_FQDN() && !$pickedObject->isType_FQDN()) && $tmp_obj->getNetworkMask() == '32' && $pickedObject->getNetworkMask() == '32' )
                                    {
                                        if(  $this->allowMergingObjectWith_m32 && ($tmp_obj->getNetworkValue() == $pickedObject->getNetworkValue()) )
                                            $exit = FALSE;
                                        else
                                        {
                                            $exit = TRUE;
                                            $exitObject = $tmp_obj;
                                        }
                                    }
                                    elseif( $tmp_obj->value() !== $pickedObject->value() )
                                    {
                                        $exit = TRUE;
                                        $exitObject = $tmp_obj;
                                    }
                                }
                            }
                        }

                        if( $exit )
                        {
                            PH::print_stdout("   * SKIP: no creation of object in DG: '" . $tmp_DG_name . "' as object with same name '{$exitObject->name()}' and different value '{$exitObject->value()}' exist at childDG/parentDG level");
                            $this->skippedObject( $index, $pickedObject, $exitObject, "object with different value exist at childDG/parentDG level");
                            continue;
                        }
                    }
                    PH::print_stdout("   * create object in DG: '" . $tmp_DG_name . "' : '" . $pickedObject->name() . "'");

                    if( $this->action === "merge" )
                    {
                        /** @var AddressStore $store */
                        if( $this->apiMode )
                            $tmp_address = $store->API_newAddress($pickedObject->name(), $pickedObject->type(), $pickedObject->value(), $pickedObject->description());
                        else
                            $tmp_address = $store->newAddress($pickedObject->name(), $pickedObject->type(), $pickedObject->value(), $pickedObject->description());

                        $value = $this->address_get_value_string( $tmp_address );
                        $hashMap[$value][] = $tmp_address;
                    }
                    else
                        $tmp_address = "[".$tmp_DG_name."] - ".$pickedObject->name(). " {new}";

                    $countChildCreated++;
                }
                elseif( $tmp_address == null )
                {
                    continue;
                }
                else
                {
                    $skip = false;
                    /** @var Address $tmp_address */
                    if( $tmp_address->isAddress() && $pickedObject->isAddress() && $tmp_address->type() === $pickedObject->type() && $tmp_address->value() === $pickedObject->value() )
                    {
                        PH::print_stdout("   * keeping object '{$tmp_address->_PANC_shortName()}'");
                    }
                    elseif( $tmp_address->isAddress() && $pickedObject->isAddress() && $tmp_address->getNetworkValue() == $pickedObject->getNetworkValue() )
                    {
                        $value = $this->address_get_value_string($tmp_address);
                        $value2 = $this->address_get_value_string($pickedObject);

                        if( $value === $value2 )
                            PH::print_stdout("   * keeping object '{$tmp_address->_PANC_shortName()}'");
                        else
                            $skip = true;
                    }
                    else
                        $skip = true;

                    if( $skip )
                    {
                        $string = "    - SKIP: object name '{$pickedObject->_PANC_shortName()}'";

                        if( $pickedObject->isAddress() )
                            $string .= " [with value '{$pickedObject->value()}']";
                        else
                            $string .= " [AdressGroup]";

                        $string .= " is not IDENTICAL to object name: '{$tmp_address->_PANC_shortName()}'";

                        if( $tmp_address->isAddress() )
                            $string .= " [with value '{$tmp_address->value()}']";
                        else
                            $string .= " [AdressGroup]";

                        PH::print_stdout($string);
                        $this->skippedObject( $index, $pickedObject, $tmp_address, "not identical");

                        continue;
                    }
                }


                // Merging loop finally!
                foreach( $hash as $objectIndex => $object )
                {
                    if( $tmp_address === null )
                        continue;

                    $checkHash = $this->address_service_hash_map_check( $index, $object, $NamehashMap, $upper_NamehashMap, $child_NamehashMap,true, true, true );
                    if( !$checkHash )
                        continue;

                    if( $this->dupAlg == 'identical' )
                        if( $object->name() != $tmp_address->name() )
                        {
                            PH::print_stdout("    - SKIP: object name '{$object->_PANC_shortName()}' [with value '{$object->value()}'] is not IDENTICAL to object name from upperlevel '{$tmp_address->_PANC_shortName()}' [with value '{$tmp_address->value()}'] ");
                            $this->skippedObject( $index, $tmp_address, $object, "not identical");
                            continue;
                        }

                    if( $object->tags->count() + $tmp_address->tags->count() > $object->tagLimit )
                    {
                        if( $this->address_tag_merge_check( $object, $tmp_address, $index) )
                            continue;
                    }

                    PH::print_stdout("    - replacing '{$object->_PANC_shortName()}' ...");
                    $success = true;
                    if( $this->action === "merge" )
                    {
                        $success = $object->__replaceWhereIamUsed($this->apiMode, $tmp_address, TRUE, 5);

                        $object->merge_tag_description_to($tmp_address, $this->apiMode);
                    }

                    if( $success )
                    {
                        PH::print_stdout("    - deleting '{$object->_PANC_shortName()}'");
                        self::deletedObject($index, $tmp_address, $object);

                        if( $this->action === "merge" )
                        {
                            if( $this->apiMode )
                                $object->owner->API_remove($object);
                            else
                                $object->owner->remove($object);
                        }

                        $countChildRemoved++;
                    }
                }

            }
            if( count( $child_hashMap ) >0 )
                PH::print_stdout( "\n\nDuplicates ChildDG removal is now done. Number of objects after cleanup: '{$store->countAddresses()}' (removed/created {$countChildRemoved}/{$countChildCreated} addresses)\n" );


            $countRemoved = 0;
            foreach( $hashMap as $index => &$hash )
            {
                PH::print_stdout();
                PH::print_stdout( " - value '{$index}'" );


                $pickedObject = $this->hashMapPickfilter( $upperHashMap, $index, $hash );


                $checkHash = $this->address_service_hash_map_check( $index, $pickedObject, $NamehashMap, $upper_NamehashMap, $child_NamehashMap,true, true, true );
                if( !$checkHash )
                    continue;

                // Merging loop finally!
                foreach( $hash as $objectIndex => $object )
                {
                    /** @var Address $object */

                    $checkHash = $this->address_service_hash_map_check( $index, $object, $NamehashMap, $upper_NamehashMap, $child_NamehashMap,true, true, true );
                    if( !$checkHash )
                        continue;

                    if( isset($object->ancestor) )
                    {
                        $ancestor = $object->ancestor;
                        $ancestor_different_value = "";

                        if( !$ancestor->isAddress() )
                        {
                            PH::print_stdout("    - SKIP: object name '{$object->_PANC_shortName()}' as one ancestor is of type addressgroup");
                            $this->skippedObject( $index, $object, $ancestor, "ancestor of type addressgroup");
                            continue;
                        }

                        /** @var Address $ancestor */
                        #if( $this->upperLevelSearch && !$ancestor->isGroup() && !$ancestor->isTmpAddr() && ($ancestor->isType_ipNetmask() || $ancestor->isType_ipRange() || $ancestor->isType_FQDN()) )
                        if( $this->upperLevelSearch && !$ancestor->isGroup() && ($ancestor->isType_ipNetmask() || $ancestor->isType_ipRange() || $ancestor->isType_FQDN()) )
                        {
                            if( $object->getIP4Mapping()->equals($ancestor->getIP4Mapping()) || ($object->isType_FQDN() && $ancestor->isType_FQDN()) && ($object->value() == $ancestor->value())  )
                            {
                                if( $this->address_get_value_string($pickedObject) != $this->address_get_value_string($ancestor) )
                                {
                                    PH::print_stdout("    - SKIP: object name '{$ancestor->_PANC_shortName()}' [with value '{$ancestor->value()}'] is not matching to object name from upperlevel '{$pickedObject->_PANC_shortName()}' [with value '{$pickedObject->value()}'] ");
                                    $this->skippedObject( $index, $pickedObject, $ancestor, "not matching to object name from upperlevel");
                                    continue;
                                }

                                if( $this->dupAlg == 'identical' )
                                    if( $pickedObject->name() != $ancestor->name() )
                                    {
                                        #PH::print_stdout("    - SKIP: object name '{$pickedObject->_PANC_shortName()}' [with value '{$pickedObject->value()}'] is not IDENTICAL to object name from upperlevel '{$ancestor->_PANC_shortName()}' [with value '{$ancestor->value()}'] ");
                                        PH::print_stdout("    - SKIP: object name '{$ancestor->_PANC_shortName()}' [with value '{$ancestor->value()}'] is not IDENTICAL to object name from upperlevel '{$pickedObject->_PANC_shortName()}' [with value '{$pickedObject->value()}'] ");
                                        $this->skippedObject( $index, $pickedObject, $ancestor, "not IDENTICAL to object name from upperlevel");
                                        continue;
                                    }

                                if( $pickedObject->tags->count() + $ancestor->tags->count() > $pickedObject->tagLimit )
                                {
                                    if( $this->address_tag_merge_check( $pickedObject, $ancestor, $index) )
                                        continue;
                                }

                                if( $this->action === "merge" )
                                    $object->merge_tag_description_to($ancestor, $this->apiMode);

                                if( isset($ancestor->owner) )
                                {
                                    $tmp_ancestor_DGname = $ancestor->owner->owner->name();
                                    if( $tmp_ancestor_DGname === "" )
                                        $tmp_ancestor_DGname = "shared";
                                }
                                else
                                    $tmp_ancestor_DGname = "shared";

                                $text = "    - object '{$object->name()}' DG: '" . $object->owner->owner->name() . "' merged with its ancestor at DG: '" . $tmp_ancestor_DGname . "', deleting: " . $object->_PANC_shortName();
                                self::deletedObject($index, $ancestor, $object);

                                if( $this->action === "merge" )
                                {
                                    $object->replaceMeGlobally($ancestor);

                                    if( $this->apiMode )
                                        $object->owner->API_remove($object);
                                    else
                                        $object->owner->remove($object);
                                }
                                PH::print_stdout($text);

                                $text = "         ancestor name: '{$ancestor->name()}' DG: ";
                                $text .= "'{$tmp_ancestor_DGname}'";
                                $text .= "  value: '{$ancestor->value()}' ";
                                PH::print_stdout($text);

                                if( $pickedObject === $object )
                                    $pickedObject = $ancestor;

                                $countRemoved++;

                                if( $this->mergeCountLimit !== FALSE && $countRemoved >= $this->mergeCountLimit )
                                {
                                    PH::print_stdout("\n *** STOPPING MERGE OPERATIONS NOW SINCE WE REACHED mergeCountLimit ({$this->mergeCountLimit})");
                                    break 2;
                                }

                                continue;
                            }
                            else
                                $ancestor_different_value = "with different value";


                        }
                        PH::print_stdout("    - object '{$object->name()}' '{$ancestor->type()}' cannot be merged because it has an ancestor " . $ancestor_different_value . "");

                        $text = "         ancestor name: '{$ancestor->name()}' DG: ";
                        if( $ancestor->owner->owner->name() == "" )
                            $text .= "'shared'";
                        else
                            $text .= "'{$ancestor->owner->owner->name()}'";
                        $text .= "  value: '{$ancestor->value()}' ";
                        PH::print_stdout($text);

                        if( $this->upperLevelSearch )
                        {
                            $tmpstring = "|->ERROR object '{$object->name()}' '{$ancestor->type()}' cannot be merged because it has an ancestor " . $ancestor_different_value . " | ".$text;
                            $this->skippedObject( $index, $object, $ancestor, $tmpstring);
                            break;
                        }
                        else
                            $tmpstring = "|-> ancestor: '" . $object->_PANC_shortName() . "' you did not allow to merged";
                        self::deletedObjectSetRemoved($index, $tmpstring);

                        continue;
                    }

                    if( $object === $pickedObject )
                    {
                        #PH::print_stdout("    - SKIPPED: '{$object->name()}' === '{$pickedObject->name()}': ");
                        continue;
                    }

                    if( $this->dupAlg != 'identical' )
                    {
                        if( $pickedObject->isType_TMP() )
                            continue;

                        if( $object->tags->count() + $pickedObject->tags->count() > $object->tagLimit )
                        {
                            if( $this->address_tag_merge_check( $object, $pickedObject, $index) )
                                continue;
                        }

                        PH::print_stdout("    - replacing '{$object->_PANC_shortName()}' ...");

                        PH::print_stdout("    - deleting '{$object->_PANC_shortName()}'");
                        self::deletedObject($index, $pickedObject, $object);

                        if( $this->action === "merge" )
                        {
                            if( $pickedObject->isType_TMP() )
                            {
                                /*
                                $context = null;
                                $context->padding = "   ";
                                $context->object = $pickedObject;
                                $pickedObject->replaceIPbyObject( $context );
                                */
                                continue;
                            }

                            $success = $object->__replaceWhereIamUsed($this->apiMode, $pickedObject, TRUE, 5);

                            $object->merge_tag_description_to($pickedObject, $this->apiMode);

                            if( $success )
                            {
                                if( $object->owner !== null )
                                {
                                    if( $this->apiMode )
                                        $object->owner->API_remove($object);
                                    else
                                        $object->owner->remove($object);

                                    $countRemoved++;
                                }
                            }
                        }

                        if( $this->mergeCountLimit !== FALSE && $countRemoved >= $this->mergeCountLimit )
                        {
                            PH::print_stdout("\n *** STOPPING MERGE OPERATIONS NOW SINCE WE REACHED mergeCountLimit ({$this->mergeCountLimit})");
                            break 2;
                        }
                    }
                    else
                    {
                        #PH::print_stdout("    - SKIP: object name '{$object->_PANC_shortName()}' [with value '{$object->value()}'] is not IDENTICAL to object name from upperlevel '{$pickedObject->_PANC_shortName()}' [with value '{$pickedObject->value()}'] ");
                        PH::print_stdout("    - SKIP: object name '{$object->_PANC_shortName()}' [with value '{$object->value()}'] is not IDENTICAL");
                    }
                }
            }

            PH::print_stdout( "\n\nDuplicates removal is now done. Number of objects after cleanup: '{$store->countAddresses()}' (removed {$countRemoved} addresses)\n" );

        }    
    }

    function address_service_hash_map_check( $index, $object, $NamehashMap, $upper_NamehashMap, $child_NamehashMap, $checkNamehashMap = false, $checkUpperhashMap = false, $checkChildhashMap = false )
    {
        $array_hashMap = array( "name"=>$NamehashMap, "upper"=>$upper_NamehashMap, "child"=>$child_NamehashMap );
        foreach( $array_hashMap as $key => $MainHashMap )
        {
            if( $key == "name" && !$checkNamehashMap )
                continue;
            elseif( $key == "upper" && !$checkUpperhashMap )
                continue;
            elseif( $key == "child" && !$checkChildhashMap )
                continue;


            if( isset($MainHashMap[$object->name()]) )
            {
                $skip2 = FALSE;
                $skip3 = FALSE;
                $skippedOBJ = null;

                $tmp_string_obj_type = "";
                foreach( $MainHashMap[$object->name()] as $key => $overridenOBJ )
                {
                    if( get_class($object) == "Address" )
                    {
                        if (!$overridenOBJ->isAddress())
                        {
                            $skip2 = TRUE;
                            $tmp_string_obj_type = "addressgroup";
                            $skippedOBJ = $overridenOBJ;
                            break;
                        }
                        if( $this->allowMergingObjectWith_m32 && ($overridenOBJ->getNetworkMask() == '32' && $object->getNetworkMask() == '32') )
                        {
                            if( $overridenOBJ->getNetworkValue() !== $object->getNetworkValue())
                            {
                                $skip3 = TRUE;
                                $skippedOBJ = $overridenOBJ;
                                break;
                            }
                        }
                        elseif ($overridenOBJ->value() !== $object->value())
                        {
                            $skip3 = TRUE;
                            $skippedOBJ = $overridenOBJ;
                            break;
                        }
                    }
                    elseif( get_class($object) == "Service" )
                    {
                        if (!$overridenOBJ->isService())
                        {
                            $skip2 = TRUE;
                            $tmp_string_obj_type = "servicegroup";
                            $skippedOBJ = $overridenOBJ;
                            break;
                        }
                        if( $overridenOBJ->getDestPort() !== $object->getDestPort() || $overridenOBJ->getSourcePort() !== $object->getSourcePort() || $overridenOBJ->protocol() !== $object->protocol() )
                        {
                            $skip3 = TRUE;
                            $skippedOBJ = $overridenOBJ;
                            break;
                        }
                    }
                }

                if ($skip2)
                {

                    PH::print_stdout("    - SKIP: object name '{$object->_PANC_shortName()}' as one ancestor is of type ".$tmp_string_obj_type);
                    $this->skippedObject($index, $object, $skippedOBJ, "ancestor of type ".$tmp_string_obj_type);

                    return FALSE;//continue
                }
                if ($skip3)
                {
                    PH::print_stdout("    - SKIP3: object name '{$object->_PANC_shortName()}' as one ancestor has same name, but different value");
                    $this->skippedObject($index, $object, $skippedOBJ, " ancestor has same name, but different value");
                    return FALSE;//continue
                }
            }
        }

        return TRUE;
    }

    function address_tag_merge_check( $pickedObject, $ancestor, $index)
    {
        $arrayPicked = array();
        foreach( $pickedObject->tags->getAll() as $key => $tagObj )
            $arrayPicked[] = $tagObj->name();

        $arrayAncestor = array();
        foreach( $ancestor->tags->getAll() as $key => $tagObj )
            $arrayAncestor[] = $tagObj->name();

        $mergeArray = array_unique( array_merge($arrayPicked, $arrayAncestor) );

        if( count($mergeArray) > $pickedObject->tagLimit )
        {
            PH::print_stdout( "    - SKIP: tag count of name '{$ancestor->_PANC_shortName()}' [with value '{$ancestor->value()}'] added with object name from upperlevel '{$pickedObject->_PANC_shortName()}' [with value '{$pickedObject->value()}'] exceed PAN-OS limit ".$pickedObject->tagLimit." with unique tag count: ".count($mergeArray) );
            $this->skippedObject( $index, $pickedObject, $ancestor, "tag object count exceed PAN-OS limit ".$pickedObject->tagLimit." with unique tag count: ".count($mergeArray) );

            #PH::print_stdout( count($mergeArray) );
            #$result=array_intersect($arrayPicked,$arrayAncestor);
            #print_r( $result );

            return true;
        }

        return false;
    }
    function address_get_value_string( $object )
    {
        $value = $object->value();
        if( ($object->isType_ipNetmask() || $object->isType_TMP() ) && strpos($object->value(), '/32') !== FALSE )
            $value = substr($value, 0, strlen($value) - 3);

        if( $object->type() === "tmp" )
            $value = "ip-netmask" . '-' . $value;
        else
            $value = $object->type() . '-' . $value;

        return $value;
    }

    function hashMapPickfilter( $upperHashMap, $index, &$hash)
    {
        $pickedObject = null;
        if( $this->pickFilter !== null )
        {
            if( isset($upperHashMap[$index]) )
            {
                $hashArray = $upperHashMap[$index];
                $printString = "   * using object from upper level : ";
            }
            else
            {
                $hashArray = $hash;
                $printString = "   * keeping object : ";
            }

            foreach( $hashArray as $object )
            {
                if( $this->pickFilter->matchSingleObject(array('object' => $object, 'nestedQueries' => &$nestedQueries)) )
                {
                    $pickedObject = $object;
                    break;
                }
            }

            if( $pickedObject === null )
            {
                if( isset($upperHashMap[$index]) )
                {
                    $hashArray = $hash;
                    $printString = "   * keeping object :";

                    foreach( $hashArray as $object )
                    {
                        if( $this->pickFilter->matchSingleObject(array('object' => $object, 'nestedQueries' => &$nestedQueries)) )
                        {
                            $pickedObject = $object;
                            break;
                        }
                    }
                }
                if( $pickedObject === null )
                    $pickedObject = reset($hash);
            }

            PH::print_stdout($printString . "'{$pickedObject->_PANC_shortName()}'");
        }
        else
        {
            if( isset($upperHashMap[$index]) )
            {
                $pickedObject = reset($upperHashMap[$index]);
                /** @var Address $pickedObject */
                if( get_class($pickedObject) === "Address" && $pickedObject->isType_TMP() )
                {
                    $pickedObject = reset($hash);
                    PH::print_stdout( "   * keeping object '{$pickedObject->_PANC_shortName()}'" );
                }
                else
                    PH::print_stdout( "   * using object from upper level : '{$pickedObject->_PANC_shortName()}'" );
            }
            else
            {
                $pickedObject = reset($hash);
                PH::print_stdout( "   * keeping object '{$pickedObject->_PANC_shortName()}'" );
            }
        }

        return $pickedObject;
    }

    function PickObject(&$hash)
    {
        $pickedObject = null;
        if( $this->pickFilter !== null )
        {
            foreach( $hash as $object )
            {
                if( $this->pickFilter->matchSingleObject(array('object' => $object, 'nestedQueries' => &$nestedQueries)) )
                {
                    $pickedObject = $object;
                    break;
                }
            }
            if( $pickedObject === null )
                $pickedObject = reset($hash);
        }
        else
            $pickedObject = reset($hash);

        return $pickedObject;
    }

    function checkParentPickObject( $hash )
    {
        $break = False;
        foreach( $hash as $pickedObject )
        {
            /** @var DeviceGroup $pickedObject_DG */
            $pickedObject_DG = $pickedObject->owner->owner;
            if( $pickedObject_DG->parentDeviceGroup !== null )
            {
                $nextFindObject = $pickedObject_DG->parentDeviceGroup->addressStore->find( $pickedObject->name(), null, True );
                if( $nextFindObject !== null )
                {
                    /** @var Address|AddressGroup $memberFound */
                    if( $pickedObject->isAddress() && $nextFindObject->isAddress() )
                    {
                        if( $pickedObject->value() !== $nextFindObject->value() )
                        {
                            PH::print_stdout("   * SKIPPED : this group has an object named '{$pickedObject->name()} that does exist in target location '{$tmp_DG_name}' with different value");
                            $break = TRUE;
                        }
                    }
                    elseif( $pickedObject->isGroup() && $nextFindObject->isGroup() )
                    {
                        $diff = $pickedObject->getValueDiff($nextFindObject);
                        if( count($diff['minus']) != 0 || count($diff['plus']) != 0 )
                        {
                            PH::print_stdout("   * SKIPPED : this group has different membership compare to upperlevel");
                            $break = TRUE;
                        }
                    }
                    else
                    {
                        PH::print_stdout("   * SKIPPED : this group has an object named '{$pickedObject->name()} that does exist in target location '{$tmp_DG_name}' with different object type");
                        $break = TRUE;
                    }
                }
            }
        }
        return $break;
    }

    function checkParentServicePickObject($hash)
    {
        $break = False;
        foreach( $hash as $pickedObject )
        {
            /** @var DeviceGroup $pickedObject_DG */
            $pickedObject_DG = $pickedObject->owner->owner;
            if( $pickedObject_DG->parentDeviceGroup !== null )
            {
                $nextFindObject = $pickedObject_DG->parentDeviceGroup->serviceStore->find( $pickedObject->name(), null, True );
                if( $nextFindObject !== null )
                {
                    /** @var Service|ServiceGroup $memberFound */
                    if( $pickedObject->isService() && $nextFindObject->isService() )
                    {
                        if( $pickedObject->getDestPort() !== $nextFindObject->getDestPort() || $pickedObject->getSourcePort() !== $nextFindObject->getSourcePort() || $pickedObject->protocol() !== $nextFindObject->protocol() )
                        {
                            PH::print_stdout("   * SKIPPED : this group has an object named '{$pickedObject->name()} that does exist in target location '{$tmp_DG_name}' with different value or protocol");
                            $break = TRUE;
                        }
                    }
                    elseif( $pickedObject->isGroup() && $nextFindObject->isGroup() )
                    {
                        //todo 20230518 check deeper if this group group part must be validate more
                        $diff = $pickedObject->getValueDiff($nextFindObject);
                        if( count($diff['minus']) != 0 || count($diff['plus']) != 0 )
                        {
                            PH::print_stdout("   * SKIPPED : this group has different member ship compare to upperlevel");
                            $break = TRUE;
                        }
                    }
                    else
                    {
                        PH::print_stdout("   * SKIPPED : this group has an object named '{$pickedObject->name()} that does exist in target location '{$tmp_DG_name}' with different object type");
                        $break = TRUE;
                    }
                }
            }
        }
        return $break;
    }

    function servicegroup_merging()
    {
        foreach( $this->location_array as $tmp_location )
        {
            $store = null;
            $findLocation = null;
            $parentStore = null;
            $childDeviceGroups = null;
            $this->locationSettings( $tmp_location, "ServiceGroup", $store,$findLocation,$parentStore,$childDeviceGroups);


            /**
             * @param ServiceGroup $object
             * @return string
             */
            if( $this->dupAlg == 'samemembers' || $this->dupAlg == 'identical' )
                $hashGenerator = function ($object) {
                    /** @var ServiceGroup $object */
                    $value = '';

                    $members = $object->members();
                    usort($members, '__CmpObjName');

                    foreach( $members as $member )
                    {
                        $value .= './.' . $member->name();
                    }

                    return $value;
                };
            elseif( $this->dupAlg == 'sameportmapping' )
                $hashGenerator = function ($object) {
                    /** @var ServiceGroup $object */
                    $value = '';

                    $mapping = $object->dstPortMapping();

                    $value = $mapping->mappingToText();

                    if( count($mapping->unresolved) > 0 )
                    {
                        ksort($mapping->unresolved);
                        $value .= '//unresolved:/';

                        foreach( $mapping->unresolved as $unresolvedEntry )
                            $value .= $unresolvedEntry->name() . '.%.';
                    }

                    return $value;
                };
            elseif( $this->dupAlg == 'whereused' )
                $hashGenerator = function ($object) {
                    if( $object->countReferences() == 0 )
                        return null;

                    /** @var ServiceGroup $object */
                    $value = $object->getRefHashComp();

                    return $value;
                };
            else
                derr("unsupported dupAlgorithm");

            //
            // Building a hash table of all service objects with same value
            //
            /** @var ServiceStore $store */
            if( $this->upperLevelSearch )
                $objectsToSearchThrough = $store->nestedPointOfView();
            else
                $objectsToSearchThrough = $store->serviceGroups();

            $child_hashMap = array();
            //todo: childDG/childDG to parentDG merge is always done; should it not combined to upperLevelSearch value?
            foreach( $childDeviceGroups as $dg )
            {
                /** @var DeviceGroup $dg */
                foreach( $dg->serviceStore->serviceGroups() as $object )
                {
                    if( !$object->isGroup() )
                        continue;

                    if( $this->excludeFilter !== null && $this->excludeFilter->matchSingleObject(array('object' => $object, 'nestedQueries' => &$nestedQueries)) )
                        continue;

                    $value = $hashGenerator($object);
                    if( $value === null )
                        continue;

                    #PH::print_stdout( "add objNAME: " . $object->name() . " DG: " . $object->owner->owner->name() );
                    $child_hashMap[$value][] = $object;
                }
            }

            $hashMap = array();
            $NamehashMap = array();
            $child_hashMap = array();
            $child_NamehashMap = array();
            $upperHashMap = array();
            $upper_NamehashMap = array();
            foreach( $objectsToSearchThrough as $object )
            {
                if( !$object->isGroup() )
                    continue;

                if( $this->excludeFilter !== null && $this->excludeFilter->matchSingleObject(array('object' => $object, 'nestedQueries' => &$nestedQueries)) )
                    continue;

                $skipThisOne = FALSE;

                // Object with descendants in lower device groups should be excluded
                if( $this->pan->isPanorama() )
                {
                    foreach( $childDeviceGroups as $dg )
                    {
                        if( $dg->serviceStore->find($object->name(), null, FALSE) !== null )
                        {
                            $skipThisOne = TRUE;
                            break;
                        }
                    }
                    if( $skipThisOne )
                        continue;
                }
                elseif( ($this->pan->isFawkes() || $this->pan->isBuckbeak()) && $object->owner === $store )
                {
                    //do something
                }

                $value = $hashGenerator($object);
                if( $value === null )
                    continue;

                if( $object->owner === $store )
                {
                    $hashMap[$value][] = $object;
                    if( $parentStore !== null )
                        $object->ancestor = self::findAncestor( $parentStore, $object, "serviceStore");

                    $object->childancestor = self::findChildAncestor( $childDeviceGroups, $object, "serviceStore");
                }
                else
                    $upperHashMap[$value][] = $object;
            }

            //
            // Hashes with single entries have no duplicate, let's remove them
            //
            $countConcernedObjects = 0;
            foreach( $hashMap as $index => &$hash )
            {
                if( count($hash) == 1 && !isset($upperHashMap[$index]) && !isset(reset($hash)->ancestor) )
                {
                    //PH::print_stdout( "\nancestor not found for ".reset($hash)->name()."" );
                    unset($hashMap[$index]);
                }
                else
                    $countConcernedObjects += count($hash);
            }
            unset($hash);
            $countConcernedChildObjects = 0;
            foreach( $child_hashMap as $index => &$hash )
            {
                if( count($hash) == 1 && !isset($upperHashMap[$index]) && !isset(reset($hash)->ancestor) )
                    unset($child_hashMap[$index]);
                else
                    $countConcernedChildObjects += count($hash);
            }
            unset($hash);

            PH::print_stdout( " - found " . count($hashMap) . " duplicate values totalling {$countConcernedObjects} groups which are duplicate" );

            PH::print_stdout( " - found " . count($child_hashMap) . " duplicates childDG values totalling {$countConcernedChildObjects} address objects which are duplicate" );


            PH::print_stdout( "\n\nNow going after each duplicates for a replacement" );

            $countChildRemoved = 0;
            $countChildCreated = 0;
            foreach( $child_hashMap as $index => &$hash )
            {
                PH::print_stdout();
                PH::print_stdout( " - value '{$index}'" );


                $pickedObject = $this->PickObject( $hash );

                //Todo: swaschkut 20241124 bring in hash map validation as for other objects types

                $tmp_DG_name = $store->owner->name();
                if( $tmp_DG_name == "" )
                    $tmp_DG_name = 'shared';

                $tmp_service = $store->find( $pickedObject->name() );
                if( $tmp_service == null && $this->dupAlg != "identical" )
                {
                    PH::print_stdout("   * move object to DG: '" . $tmp_DG_name . "' : '" . $pickedObject->name() . "' from DG: '".$pickedObject->owner->owner->name()."'");

                    $skip = FALSE;


                    $break = $this->checkParentServicePickObject( $hash );
                    if( $break )
                    {
                        PH::print_stdout("     this object can not be created" );
                        continue;
                    }

                    foreach( $pickedObject->members() as $memberObject )
                    {
                        $memberFound = $store->find($memberObject->name());
                        if( $memberFound === null )
                        {
                            PH::print_stdout("   * SKIPPED : this group has an object named '{$memberObject->name()} that does not exist in target location '{$tmp_DG_name}'");
                            $skip = TRUE;
                            break;
                        }
                        else
                        {
                            /** @var Service|ServiceGroup $memberFound */
                            if( $memberFound->isService() && $memberObject->isService() )
                            {
                                if( $memberFound->getDestPort() !== $memberObject->getDestPort() || $memberFound->getSourcePort() !== $memberObject->getSourcePort() || $memberFound->protocol() !== $memberObject->protocol() )
                                {
                                    PH::print_stdout("   * SKIPPED : this group has an object named '{$memberObject->name()} that does exist in target location '{$tmp_DG_name}' with different value or protocol");
                                    $skip = TRUE;
                                    break;
                                }
                            }
                            elseif( $memberFound->isGroup() && $memberObject->isGroup() )
                            {
                                $diff = $memberObject->getValueDiff($memberFound);
                                if( count($diff['minus']) != 0 || count($diff['plus']) != 0 )
                                {
                                    PH::print_stdout("   * SKIPPED : this group has different member ship compare to upperlevel");
                                    $skip = TRUE;
                                }
                            }
                            else
                            {
                                PH::print_stdout("   * SKIPPED : this group has an object named '{$memberObject->name()} that does exist in target location '{$tmp_DG_name}' with different object type");
                                $skip = TRUE;
                                break;
                            }

                        }
                    }
                    if( $skip )
                        continue;

                    if( $this->action === "merge" )
                    {
                        /** @var AddressStore $store */
                        if( $this->apiMode )
                        {
                            $oldXpath = $pickedObject->getXPath();
                            $pickedObject->owner->remove($pickedObject);
                            $store->add($pickedObject);
                            $pickedObject->API_sync();
                            $this->pan->connector->sendDeleteRequest($oldXpath);
                        }
                        else
                        {
                            $pickedObject->owner->remove($pickedObject);
                            $store->add($pickedObject);
                        }
                        $tmp_service = $store->find( $pickedObject->name() );
                        $value = $hashGenerator($tmp_service);
                        $hashMap[$value][] = $tmp_service;
                    }

                    $countChildCreated++;
                }
                elseif( $tmp_service === null )
                    continue;
                else
                {
                    if( !$tmp_service->isGroup() )
                    {
                        PH::print_stdout( "    - SKIP: object name '{$pickedObject->_PANC_shortName()}' of type ".get_class($pickedObject)." can not be merged with object name: '{$tmp_service->_PANC_shortName()}' of type ".get_class($tmp_service) );
                        $this->skippedObject( $index, $pickedObject, $tmp_service, "object type is not service-group");
                        continue;
                    }

                    $pickedObject_value = $hashGenerator($pickedObject);
                    $tmp_service_value = $hashGenerator($tmp_service);

                    if( $pickedObject_value == $tmp_service_value )
                    {
                        PH::print_stdout( "   * keeping object '{$tmp_service->_PANC_shortName()}'" );
                    }
                    else
                    {
                        PH::print_stdout( "    - SKIP: object name '{$pickedObject->_PANC_shortName()}' [with value '{$pickedObject_value}'] is not IDENTICAL to object name: '{$tmp_service->_PANC_shortName()}' [with value '{$tmp_service_value}']" );
                        $this->skippedObject( $index, $pickedObject, $tmp_service, "not identical");
                        continue;
                    }
                }


                // Merging loop finally!
                foreach( $hash as $objectIndex => $object )
                {
                    if( $tmp_service === null )
                        continue;

                    //Todo: swaschkut 20241124 bring in hash map validation as for other objects types

                    if( $this->dupAlg == 'identical' )
                        if( $object->name() != $tmp_service->name() )
                        {
                            PH::print_stdout("    - SKIP: object name '{$object->_PANC_shortName()}' is not IDENTICAL to object name from upperlevel '{$tmp_service->_PANC_shortName()}'");
                            $this->skippedObject( $index, $tmp_service, $object, "not identical");
                            continue;
                        }

                    if( $object !== $tmp_service )
                    {
                        PH::print_stdout("    - group '{$object->name()}' DG: '" . $object->owner->owner->name() . "' merged with its ancestor at DG: '" . $tmp_service->owner->owner->name() . "', deleting: " . $object->_PANC_shortName());
                        self::deletedObject($index, $tmp_service, $object);
                        PH::print_stdout("    - replacing '{$object->_PANC_shortName()}' ...");
                        if( $this->action === "merge" )
                        {
                            $success = $object->__replaceWhereIamUsed($this->apiMode, $tmp_service, TRUE, 5);

                            if( $success )
                            {
                                if( $this->apiMode )
                                    $object->owner->API_remove($object, TRUE);
                                else
                                    $object->owner->remove($object, TRUE);

                                $countChildRemoved++;
                            }
                        }
                    }
                }
            }
            if( count( $child_hashMap ) >0 )
                PH::print_stdout( "\n\nDuplicates ChildDG removal is now done. Number of objects after cleanup: '{$store->countServiceGroups()}' (removed/created {$countChildRemoved}/{$countChildCreated} servicegroups)\n" );



            $countRemoved = 0;
            foreach( $hashMap as $index => &$hash )
            {
                PH::print_stdout();

                if( $this->dupAlg == 'sameportmapping' )
                    PH::print_stdout(" - value '{$index}'");
                else
                    PH::print_stdout(" - value '{$index}'");

                $setList = array();
                foreach( $hash as $object )
                {
                    /** @var Service $object */
                    $setList[] = PH::getLocationString($object->owner->owner) . '/' . $object->name();
                }
                PH::print_stdout(" - duplicate set : '" . PH::list_to_string($setList) . "'");


                $pickedObject = $this->hashMapPickfilter( $upperHashMap, $index, $hash );

                //Todo: swaschkut 20241124 bring in hash map validation as for other objects types

                // Merging loop finally!
                foreach( $hash as $object )
                {
                    /** @var ServiceGroup $object */

                    //Todo: swaschkut 20241124 bring in hash map validation as for other objects types

                    if( isset($object->ancestor) )
                    {
                        $ancestor = $object->ancestor;
                        /** @var ServiceGroup $ancestor */
                        if( $this->upperLevelSearch && $ancestor->isGroup() )
                        {
                            if( $this->dupAlg == 'identical' )
                                if( $object->name() != $ancestor->name() )
                                {
                                    PH::print_stdout("    - SKIP: object name '{$ancestor->_PANC_shortName()}' is not IDENTICAL to object name from upperlevel '{$object->_PANC_shortName()}'");
                                    $this->skippedObject( $index, $object, $ancestor, "not identical");
                                    continue;
                                }

                            if( $hashGenerator($object) != $hashGenerator($ancestor) )
                            {

                                $this->servicegroupGetValueDiff($ancestor, $object, true);
                            }

                            if( $hashGenerator($object) == $hashGenerator($ancestor) )
                            {
                                if( isset($ancestor->owner) )
                                {
                                    $tmp_ancestor_DGname = $ancestor->owner->owner->name();
                                    if( $tmp_ancestor_DGname === "" )
                                        $tmp_ancestor_DGname = "shared";
                                }
                                else
                                    $tmp_ancestor_DGname = "shared";
                                $text = "    - group '{$object->name()}' DG: '" . $object->owner->owner->name() . "' merged with its ancestor at DG: '" . $tmp_ancestor_DGname . "', deleting: " . $object->_PANC_shortName();
                                self::deletedObject($index, $ancestor, $object);
                                if( $this->action === "merge" )
                                {
                                    $object->replaceMeGlobally($ancestor);
                                    if( $this->apiMode )
                                        $object->owner->API_remove($object);
                                    else
                                        $object->owner->remove($object);
                                }

                                PH::print_stdout($text);

                                if( $pickedObject === $object )
                                    $pickedObject = $ancestor;

                                $countRemoved++;
                                if( $this->mergeCountLimit !== FALSE && $countRemoved >= $this->mergeCountLimit )
                                {
                                    PH::print_stdout("\n *** STOPPING MERGE OPERATIONS NOW SINCE WE REACHED mergeCountLimit ({$this->mergeCountLimit})");
                                    break 2;
                                }
                                continue;
                            }
                        }
                        if( isset($ancestor->owner) )
                        {
                            $tmp_ancestor_DGname = $ancestor->owner->owner->name();
                            if( $tmp_ancestor_DGname === "" )
                                $tmp_ancestor_DGname = "shared";
                        }
                        else
                            $tmp_ancestor_DGname = "shared";
                        PH::print_stdout("    - group '{$object->name()}' cannot be merged because it has an ancestor at DG: ".$tmp_ancestor_DGname );
                        PH::print_stdout( "    - ancestor type: ".get_class( $ancestor ) );
                        $this->skippedObject( $index, $object, $ancestor, "ancestor type: ".get_class( $ancestor ));
                        continue;
                    }

                    if( isset( $object->childancestor ) )
                    {
                        $childancestor = $object->childancestor;

                        if( $childancestor !== null )
                        {
                            if( !$childancestor->isGroup() )
                            {
                                PH::print_stdout("    - SKIP: object name '{$object->_PANC_shortName()}' as one ancestor is of type: ". get_class( $childancestor )." '{$childancestor->_PANC_shortName()}' value: ".$childancestor->value());
                                $this->skippedObject( $index, $object, $childancestor, 'childancestor of type: '.get_class( $childancestor ));
                                break;
                            }

                            //Todo check ip4mapping of $childancestor and $object
                            /*
                            if( $hashGenerator($object) == $hashGenerator($ancestor) )
                            {
                                print "additional validation needed if same value\n";
                                break;
                            }
                            else
                            {
                                */
                            $this->servicegroupGetValueDiff($childancestor, $object, true);

                            if( isset($childancestor->owner) )
                            {
                                $tmp_ancestor_DGname = $childancestor->owner->owner->name();
                                if( $tmp_ancestor_DGname === "" )
                                    $tmp_ancestor_DGname = "shared";
                            }
                            else
                                $tmp_ancestor_DGname = "shared";



                            PH::print_stdout("    - group '{$object->name()}' cannot be merged because it has an ancestor at DG: ".$tmp_ancestor_DGname );
                            PH::print_stdout( "    - ancestor type: ".get_class( $childancestor ) );
                            $this->skippedObject( $index, $object, $childancestor, 'childancestor at DG: '.$tmp_ancestor_DGname);

                            break;
                            //}
                        }
                    }
                    if( $object === $pickedObject )
                    {
                        #PH::print_stdout("    - SKIPPED: '{$object->name()}' === '{$pickedObject->name()}': ");
                        continue;
                    }

                    if( $this->dupAlg == 'whereused' )
                    {
                        PH::print_stdout("    - merging '{$object->name()}' members into '{$pickedObject->name()}': ");
                        foreach( $object->members() as $member )
                        {
                            $text = "     - adding member '{$member->name()}'... ";
                            if( $this->action === "merge" )
                            {
                                if( $this->apiMode )
                                    $pickedObject->API_addMember($member);
                                else
                                    $pickedObject->addMember($member);
                            }

                            PH::print_stdout($text);
                        }
                        PH::print_stdout("    - now removing '{$object->name()} from where it's used");
                        $text = "    - deleting '{$object->name()}'... ";
                        self::deletedObject($index, $pickedObject, $object);
                        if( $this->action === "merge" )
                        {
                            if( $this->apiMode )
                            {
                                $object->API_removeWhereIamUsed(TRUE, 6);
                                $object->owner->API_remove($object);
                            }
                            else
                            {
                                $object->removeWhereIamUsed(TRUE, 6);
                                $object->owner->remove($object);
                            }
                        }
                        PH::print_stdout($text);
                    }
                    else
                    {
                        if( $this->dupAlg == 'identical' )
                            if( $object->name() != $pickedObject->name() )
                            {
                                PH::print_stdout("    - SKIP: object name '{$pickedObject->_PANC_shortName()}' is not IDENTICAL to object name from upperlevel '{$object->_PANC_shortName()}'");
                                $this->skippedObject( $index, $object, $pickedObject, "not identical");
                                continue;
                            }

                        PH::print_stdout("    - replacing '{$object->_PANC_shortName()}' ...");
                        $success = true;
                        if( $this->action === "merge" )
                            $success = $object->__replaceWhereIamUsed($this->apiMode, $pickedObject, TRUE, 5);

                        if( $success )
                        {
                            PH::print_stdout("    - deleting '{$object->_PANC_shortName()}'");
                            self::deletedObject($index, $pickedObject, $object);
                            if( $this->action === "merge" )
                            {
                                if( $this->apiMode )
                                    //true flag needed for nested groups in a specific constellation
                                    $object->owner->API_remove($object, TRUE);
                                else
                                    $object->owner->remove($object, TRUE);
                            }
                        }
                    }

                    $countRemoved++;

                    if( $this->mergeCountLimit !== FALSE && $countRemoved >= $this->mergeCountLimit )
                    {
                        PH::print_stdout("\n *** STOPPING MERGE OPERATIONS NOW SINCE WE REACHED mergeCountLimit ({$this->mergeCountLimit})");
                        break 2;
                    }
                }

            }

            PH::print_stdout( "\n\nDuplicates removal is now done. Number of objects after cleanup: '{$store->countServiceGroups()}' (removed {$countRemoved} groups)\n" );

        }
    }

    function servicegroupGetValueDiff( $ancestor, $object, $display = false)
    {
        if( $display )
            $ancestor->displayValueDiff($object, 7);

        if( $this->addMissingObjects )
        {
            $diff = $ancestor->getValueDiff($object);

            if( count($diff['minus']) != 0 )
                foreach( $diff['minus'] as $d )
                {
                    /** @var Service|ServiceGroup $d */

                    if( $ancestor->owner->find($d->name()) !== null )
                    {
                        PH::print_stdout("      - adding objects to group: " . $d->name() . "");
                        if( $this->action === "merge" )
                        {
                            if( $this->apiMode )
                                $ancestor->API_addMember($d);
                            else
                                $ancestor->addMember($d);
                        }
                    }
                    else
                    {
                        PH::print_stdout("      - object not found: " . $d->name() . "");
                    }
                }

            if( count($diff['plus']) != 0 )
                foreach( $diff['plus'] as $d )
                {
                    /** @var Service|ServiceGroup $d */
                    //TMP usage to clean DG level SERVICEgroup up
                    if( $this->action === "merge" )
                        $object->addMember($d);
                }
        }
    }

    function service_merging()
    {
        foreach( $this->location_array as $tmp_location )
        {
            $store = null;
            $findLocation = null;
            $parentStore = null;
            $childDeviceGroups = null;
            $this->locationSettings( $tmp_location, "Service", $store, $findLocation,$parentStore,$childDeviceGroups);


//
// Building a hash table of all service based on their REAL port mapping
//
            if( $this->upperLevelSearch )
                $objectsToSearchThrough = $store->nestedPointOfView();
            else
                $objectsToSearchThrough = $store->serviceObjects();

            $hashMap = array();
            $NamehashMap = array();
            $child_hashMap = array();
            $child_NamehashMap = array();
            $upperHashMap = array();
            $upper_NamehashMap = array();

            if( $this->dupAlg == 'sameports' || $this->dupAlg == 'samedstsrcports' || $this->dupAlg == 'identical' )
            {
                //todo: childDG/childDG to parentDG merge is always done; should it not combined to upperLevelSearch value?
                foreach( $childDeviceGroups as $dg )
                {
                    /** @var DeviceGroup $dg */
                    foreach( $dg->serviceStore->serviceObjects() as $object )
                    {
                        if( !$object->isService() )
                            continue;
                        if( $object->isTmpSrv() )
                            continue;

                        if( $this->excludeFilter !== null && $this->excludeFilter->matchSingleObject(array('object' => $object, 'nestedQueries' => &$nestedQueries)) )
                            continue;

                        $value = $object->dstPortMapping()->mappingToText();

                        #PH::print_stdout( "add objNAME: " . $object->name() . " DG: " . $object->owner->owner->name() . "" );
                        $child_hashMap[$value][] = $object;
                        $child_NamehashMap[$object->name()][] = $object;
                    }
                }

                foreach( $objectsToSearchThrough as $object )
                {
                    /** @var Service $object */

                    if( !$object->isService() )
                        continue;
                    if( $object->isTmpSrv() )
                        continue;

                    if( $this->excludeFilter !== null && $this->excludeFilter->matchSingleObject(array('object' => $object, 'nestedQueries' => &$nestedQueries)) )
                        continue;


                    $skipThisOne = FALSE;

                    // Object with descendants in lower device groups should be excluded
                    if( $this->pan->isPanorama() )
                    {
                        //do something
                    }
                    elseif( ($this->pan->isFawkes() || $this->pan->isBuckbeak()) && $object->owner === $store )
                    {
                        //do something
                    }

                    $value = $object->dstPortMapping()->mappingToText();

                    if( $object->owner === $store )
                    {
                        $hashMap[$value][] = $object;
                        $NamehashMap[$object->name()][] = $object;
                        if( $parentStore !== null )
                            $object->ancestor = self::findAncestor($parentStore, $object, "serviceStore");

                        $object->childancestor = self::findChildAncestor( $childDeviceGroups, $object, "serviceStore");
                    }
                    else
                    {
                        $upperHashMap[$value][] = $object;
                        $upper_NamehashMap[$object->name()][] = $object;
                    }


                }
            }
            elseif( $this->dupAlg == 'whereused' )
            {
                foreach( $objectsToSearchThrough as $object )
                {
                    if( !$object->isService() )
                        continue;
                    if( $object->isTmpSrv() )
                        continue;

                    if( $object->countReferences() == 0 )
                        continue;

                    if( $this->excludeFilter !== null && $this->excludeFilter->matchSingleObject(array('object' => $object, 'nestedQueries' => &$nestedQueries)) )
                        continue;

                    $value = $object->getRefHashComp() . $object->protocol();
                    if( $object->owner === $store )
                    {
                        $hashMap[$value][] = $object;
                        $NamehashMap[$object->name()][] = $object;
                        if( $parentStore !== null )
                            $object->ancestor = self::findAncestor($parentStore, $object, "serviceStore");
                        $object->childancestor = self::findChildAncestor( $childDeviceGroups, $object, "serviceStore");
                    }
                    else
                    {
                        $upperHashMap[$value][] = $object;
                        $upper_NamehashMap[$object->name()][] = $object;
                    }

                }
            }
            else derr("unsupported use case");

//
// Hashes with single entries have no duplicate, let's remove them
//
            $countConcernedObjects = 0;
            self::removeSingleEntries( $hashMap, $child_hashMap, $upperHashMap, $countConcernedObjects);

            $countConcernedChildObjects = 0;
            self::removeSingleEntries( $child_hashMap, $hashMap, $upperHashMap, $countConcernedChildObjects);


            PH::print_stdout( " - found " . count($hashMap) . " duplicates values totalling {$countConcernedObjects} service objects which are duplicate" );

            PH::print_stdout( " - found " . count($child_hashMap) . " duplicates childDG values totalling {$countConcernedChildObjects} service objects which are duplicate" );


            PH::print_stdout( "\n\nNow going after each duplicates for a replacement" );

            $countRemoved = 0;
            if( $this->dupAlg == 'sameports' || $this->dupAlg == 'samedstsrcports' || $this->dupAlg == 'identical' )
            {
                $countChildRemoved = 0;
                $countChildCreated = 0;
                foreach( $child_hashMap as $index => &$hash )
                {
                    PH::print_stdout();
                    PH::print_stdout( " - value '{$index}'" );


                    $pickedObject = $this->PickObject( $hash );

                    $checkHash = $this->address_service_hash_map_check( $index, $pickedObject, $NamehashMap, $upper_NamehashMap, $child_NamehashMap,true, true, true );
                    if( !$checkHash )
                        continue;

                    $tmp_DG_name = $store->owner->name();
                    if( $tmp_DG_name == "" )
                        $tmp_DG_name = 'shared';

                    /** @var Service $tmp_service */
                    $tmp_service = $store->find( $pickedObject->name() );
                    if( $tmp_service == null && $this->dupAlg != 'identical'  )
                    {
                        if( isset( $child_NamehashMap[ $pickedObject->name() ] ) )
                        {
                            $exit = false;
                            $exitObject = null;
                            foreach( $child_NamehashMap[ $pickedObject->name() ] as $obj )
                            {
                                /** @var Service $obj */
                                if( !$obj->dstPortMapping()->equals($pickedObject->dstPortMapping())
                                    || !$obj->srcPortMapping()->equals($pickedObject->srcPortMapping())
                                    || $obj->getOverride() != $pickedObject->getOverride()
                                    || $obj->protocol() != $pickedObject->protocol()
                                )
                                {
                                    $exit = true;
                                    $exitObject = $obj;
                                }

                                if( isset($obj->owner->parentCentralStore) )
                                {
                                    $tmpParentStore = $obj->owner->parentCentralStore;
                                    $tmp_obj = $tmpParentStore->find( $pickedObject->name(), null, true );
                                    if( $tmp_obj !== null )
                                    {
                                        /** @var Service $tmp_obj */
                                        if( !$tmp_obj->dstPortMapping()->equals($pickedObject->dstPortMapping())
                                            || !$tmp_obj->srcPortMapping()->equals($pickedObject->srcPortMapping())
                                            || $tmp_obj->getOverride() != $pickedObject->getOverride()
                                            || $tmp_obj->protocol() != $pickedObject->protocol()
                                        )
                                        {
                                            $exit = true;
                                            $exitObject = $tmp_obj;
                                        }
                                    }
                                }
                            }

                            if( $exit )
                            {
                                PH::print_stdout( "   * SKIP: no creation of object in DG: '".$tmp_DG_name."' as object with same name '{$exitObject->name()}' and different value '{$exitObject->dstPortMapping()->mappingToText()}' exist at childDG level" );
                                $this->skippedObject( $index, $pickedObject, $exitObject, "different value");
                                continue;
                            }
                        }
                        PH::print_stdout( "   * create object in DG: '".$tmp_DG_name."' : '".$pickedObject->name()."'" );

                        if( $this->action === "merge" )
                        {
                            /** @var ServiceStore $store */
                            if( $this->apiMode )
                                $tmp_service = $store->API_newService($pickedObject->name(), $pickedObject->protocol(), $pickedObject->getDestPort(), $pickedObject->description(), $pickedObject->getSourcePort());
                            else
                                $tmp_service = $store->newService($pickedObject->name(), $pickedObject->protocol(), $pickedObject->getDestPort(), $pickedObject->description(), $pickedObject->getSourcePort());

                            $value = $tmp_service->dstPortMapping()->mappingToText();
                            $hashMap[$value][] = $tmp_service;
                            $NamehashMap[$tmp_service->name()][] = $tmp_service;
                        }
                        else
                            $tmp_service = "[".$tmp_DG_name."] - ".$pickedObject->name()." {new}";

                        $countChildCreated++;
                    }
                    elseif( $tmp_service == null )
                    {
                        continue;
                    }
                    else
                    {
                        if( $tmp_service->equals( $pickedObject ) )
                        {
                            PH::print_stdout( "   * keeping object '{$tmp_service->_PANC_shortName()}'" );
                        }
                        else
                        {
                            $string = "    - SKIP: object name '{$pickedObject->_PANC_shortName()}'\n";
                            if( $pickedObject->isService() )
                            {
                                $string .= "            [protocol '{$pickedObject->protocol()}']";
                                $string .= " [with dport value '{$pickedObject->getDestPort()}']";
                                if( !empty($pickedObject->getSourcePort()) )
                                    $string .= " [with sport value '{$pickedObject->getSourcePort()}']\n";
                                else
                                    $string .= "\n";
                            }

                            else
                                $string .= " [ServiceGroup] ";

                            $string .= "       is not IDENTICAL to object name: '{$tmp_service->_PANC_shortName()}'\n";

                            if( $tmp_service->isService() )
                            {
                                $string .= "            [protocol '{$tmp_service->protocol()}']";
                                $string .= " [with dport value '{$tmp_service->getDestPort()}']";
                                if( !empty($tmp_service->getSourcePort()) )
                                    $string .= " [with sport value '{$tmp_service->getSourcePort()}']";
                                else
                                    $string .= "\n";
                            }
                            else
                                $string .= " [ServiceGroup] ";

                            PH::print_stdout( $string );
                            $this->skippedObject( $index, $pickedObject, $tmp_service, "not identical");

                            continue;
                        }
                    }


                    // Merging loop finally!
                    foreach( $hash as $objectIndex => $object )
                    {
                        if( $tmp_service === null )
                            continue;

                        $checkHash = $this->address_service_hash_map_check( $index, $object, $NamehashMap, $upper_NamehashMap, $child_NamehashMap,true, true, true );
                        if( !$checkHash )
                            continue;

                        //validate if object with same name at upperlevel has same type / value
                        //if not it can be a problem if the upperlevel obejct is used in an upperlevel addressgroup and this address-group is used at same level as $object is located
                        if( isset( $upper_NamehashMap[$object->name()] ) )
                        {
                            $skip2 = FALSE;
                            $skip3 = FALSE;
                            $skippedOBJ = null;

                            foreach( $upper_NamehashMap[$pickedObject->name()] as $key => $overridenOBJ )
                            {
                                if( !$overridenOBJ->isService() )
                                {
                                    $skip2 = TRUE;
                                    $skippedOBJ = $overridenOBJ;
                                    break;
                                }
                                /** @var Service $object */
                                if( !$object->dstPortMapping()->equals($overridenOBJ->dstPortMapping())
                                    || !$object->srcPortMapping()->equals($overridenOBJ->srcPortMapping())
                                    || $object->getOverride() != $overridenOBJ->getOverride()
                                    || $object->protocol() != $overridenOBJ->protocol()
                                )
                                {
                                    $skip3 = TRUE;
                                    $skippedOBJ = $overridenOBJ;
                                    break;
                                }
                            }

                            if( $skip2 )
                            {
                                PH::print_stdout("    - SKIP: object name '{$object->_PANC_shortName()}' as one ancestor is of type addressgroup");
                                $this->skippedObject( $index, $object, $skippedOBJ, "ancestor of type addressgroup");
                                continue;
                            }
                            if( $skip3 )
                            {
                                PH::print_stdout("    - SKIP: object name '{$object->_PANC_shortName()}' as one ancestor has same name, but different value");
                                $this->skippedObject( $index, $object, $skippedOBJ, " ancestor has same name, but different value");
                                continue;
                            }
                        }

                        if( $this->dupAlg == 'identical' )
                            if( $object->name() != $tmp_service->name() )
                            {
                                PH::print_stdout("    - SKIP: object name '{$object->_PANC_shortName()}' is not IDENTICAL to object name from upperlevel '{$tmp_service->_PANC_shortName()}'");
                                $this->skippedObject( $index, $tmp_service, $object);
                                continue;
                            }

                        $skipped = $this->servicePickedObjectValidation( $index, $object, $tmp_service );
                        if( $skipped )
                            continue;

                        PH::print_stdout("    - replacing '{$object->_PANC_shortName()}' ...");

                        $success = true;
                        if( $this->action === "merge" )
                            $success = $object->__replaceWhereIamUsed($this->apiMode, $tmp_service, TRUE, 5);

                        #$object->merge_tag_description_to($tmp_service, $this->apiMode);
                        if( $success )
                        {
                            PH::print_stdout("    - deleting '{$object->_PANC_shortName()}'");
                            self::deletedObject($index, $tmp_service, $object);

                            if( $this->action === "merge" )
                            {
                                if( $this->apiMode )
                                    $object->owner->API_remove($object);
                                else
                                    $object->owner->remove($object);
                            }
                            $countChildRemoved++;
                        }

                    }
                }

                if( count( $child_hashMap ) >0 )
                    PH::print_stdout( "\n\nDuplicates ChildDG removal is now done. Number of objects after cleanup: '{$store->countServices()}' (removed/created {$countChildRemoved}/{$countChildCreated} services)\n" );


                foreach( $hashMap as $index => &$hash )
                {
                    PH::print_stdout();
                    PH::print_stdout( " - value '{$index}'" );


                    $pickedObject = $this->hashMapPickfilter( $upperHashMap, $index, $hash );

                    $checkHash = $this->address_service_hash_map_check( $index, $pickedObject, $NamehashMap, $upper_NamehashMap, $child_NamehashMap,true, true, true );
                    if( !$checkHash )
                        continue;


                    foreach( $hash as $object )
                    {
                        /** @var Service $object */

                        $checkHash = $this->address_service_hash_map_check( $index, $object, $NamehashMap, $upper_NamehashMap, $child_NamehashMap,true, true, true );
                        if( !$checkHash )
                            continue;

                        if( isset($object->ancestor) )
                        {
                            $ancestor = $object->ancestor;

                            if( !$ancestor->isService() )
                            {
                                PH::print_stdout( "    - SKIP: object name '{$object->_PANC_shortName()}' as one ancestor is of type servicegroup" );
                                $this->skippedObject( $index, $object, $ancestor);
                                continue;
                            }

                            /** @var Service $ancestor */
                            if( $this->upperLevelSearch && !$ancestor->isGroup() && !$ancestor->isTmpSrv() )
                            {
                                if( $object->dstPortMapping()->equals($ancestor->dstPortMapping()) )
                                {
                                    $skipped = $this->servicePickedObjectValidation( $index, $object, $ancestor );
                                    if( $skipped )
                                        continue;

                                    if( $this->dupAlg == 'identical' )
                                        if( $object->name() != $ancestor->name() )
                                        {
                                            PH::print_stdout("    - SKIP: object name '{$object->_PANC_shortName()}' is not IDENTICAL to object name from upperlevel '{$ancestor->_PANC_shortName()}'");
                                            $this->skippedObject( $index, $ancestor, $object);
                                            continue;
                                        }

                                    $text = "    - object '{$object->name()}' merged with its ancestor, deleting: ".$object->_PANC_shortName();
                                    self::deletedObject($index, $ancestor, $object);
                                    if( $this->action === "merge" )
                                    {
                                        $object->replaceMeGlobally($ancestor);
                                        if( $this->apiMode )
                                            $object->owner->API_remove($object, TRUE);
                                        else
                                            $object->owner->remove($object, TRUE);
                                    }

                                    PH::print_stdout( $text );

                                    $text = "         ancestor name: '{$ancestor->name()}' DG: ";
                                    if( $ancestor->owner->owner->name() == "" ) $text .= "'shared'";
                                    else $text .= "'{$ancestor->owner->owner->name()}'";
                                    $text .=  "  value: '{$ancestor->protocol()}/{$ancestor->getDestPort()}' ";
                                    PH::print_stdout( $text );

                                    if( $pickedObject === $object )
                                        $pickedObject = $ancestor;

                                    $countRemoved++;
                                    continue;
                                }
                            }
                            PH::print_stdout( "    - object '{$object->name()}' cannot be merged because it has an ancestor" );

                            $text = "         ancestor name: '{$ancestor->name()}' DG: ";
                            if( $ancestor->owner->owner->name() == "" ) $text .= "'shared'";
                            else $text .= "'{$ancestor->owner->owner->name()}'";
                            $text .=  "  value: '{$ancestor->protocol()}/{$ancestor->getDestPort()}' ";
                            PH::print_stdout( $text );

                            if( $this->upperLevelSearch )
                            {
                                $tmpstring = "|->ERROR ancestor: '" . $object->_PANC_shortName() . "' cannot be merged. | ".$text;
                                $this->skippedObject( $index, $object, $ancestor, $tmpstring);
                            }
                            else
                                $tmpstring = "|-> ancestor: '" . $object->_PANC_shortName() . "' you did not allow to merged";
                            self::deletedObjectSetRemoved( $index, $tmpstring );

                            continue;
                        }
                        else
                        {
                            $skipped = $this->servicePickedObjectValidation( $index, $object, $pickedObject );
                            if( $skipped )
                                continue;
                        }

                        if( $object === $pickedObject )
                        {
                            #PH::print_stdout("    - SKIPPED: '{$object->name()}' === '{$pickedObject->name()}': ");
                            continue;
                        }

                        if( $this->dupAlg == 'identical' )
                            if( $object->name() != $pickedObject->name() )
                            {
                                PH::print_stdout("    - SKIP: object name '{$object->_PANC_shortName()}' is not IDENTICAL to object name from upperlevel '{$pickedObject->_PANC_shortName()}'");
                                $this->skippedObject( $index, $pickedObject, $object);
                                continue;
                            }

                        PH::print_stdout("    - replacing '{$object->_PANC_shortName()}' ...");
                        $success = true;
                        if( $this->action === "merge" )
                            $success = $object->__replaceWhereIamUsed($this->apiMode, $pickedObject, TRUE, 5);

                        if($success)
                        {
                            PH::print_stdout("    - deleting '{$object->_PANC_shortName()}'");
                            self::deletedObject($index, $pickedObject, $object);
                            if( $this->action === "merge" )
                            {
                                if( $this->apiMode )
                                    $object->owner->API_remove($object, TRUE);
                                else
                                    $object->owner->remove($object, TRUE);
                            }

                            $countRemoved++;
                        }


                        if( $this->mergeCountLimit !== FALSE && $countRemoved >= $this->mergeCountLimit )
                        {
                            PH::print_stdout( "\n *** STOPPING MERGE OPERATIONS NOW SINCE WE REACHED mergeCountLimit ({$this->mergeCountLimit})" );
                            break 2;
                        }
                    }
                }
            }
            elseif( $this->dupAlg == 'whereused' )
                foreach( $hashMap as $index => &$hash )
                {
                    PH::print_stdout();

                    $setList = array();
                    foreach( $hash as $object )
                    {
                        /** @var Service $object */
                        $setList[] = PH::getLocationString($object->owner->owner) . '/' . $object->name();
                    }
                    PH::print_stdout( " - duplicate set : '" . PH::list_to_string($setList) . "'" );


                    $pickedObject = $this->PickObject( $hash);

                    $checkHash = $this->address_service_hash_map_check( $index, $pickedObject, $NamehashMap, $upper_NamehashMap, $child_NamehashMap,true, true, true );
                    if( !$checkHash )
                        continue;

                    PH::print_stdout( "   * keeping object '{$pickedObject->_PANC_shortName()}'" );


                    foreach( $hash as $object )
                    {
                        /** @var Service $object */

                        $checkHash = $this->address_service_hash_map_check( $index, $object, $NamehashMap, $upper_NamehashMap, $child_NamehashMap,true, true, true );
                        if( !$checkHash )
                            continue;

                        if( isset($object->ancestor) )
                        {
                            $ancestor = $object->ancestor;
                            /** @var Service $ancestor */
                            PH::print_stdout( "    - object '{$object->name()}' cannot be merged because it has an ancestor" );

                            $text = "         ancestor name: '{$ancestor->name()}' DG: ";
                            if( $ancestor->owner->owner->name() == "" ) $text .= "'shared'";
                            else $text .= "'{$ancestor->owner->owner->name()}'";
                            $text .=  "  value: '{$ancestor->protocol()}/{$ancestor->getDestPort()}' ";
                            PH::print_stdout( $text );

                            if( $this->upperLevelSearch )
                            {
                                $tmpstring = "|->ERROR ancestor: '" . $object->_PANC_shortName() . "' cannot be merged. | ".$text ;
                                $this->skippedObject( $index, $object, $ancestor, $tmpstring);
                            }
                            else
                                $tmpstring = "|-> ancestor: '" . $object->_PANC_shortName() . "' you did not allow to merged";
                            self::deletedObjectSetRemoved( $index, $tmpstring );

                            continue;
                        }

                        if( $object === $pickedObject )
                        {
                            #PH::print_stdout("    - SKIPPED: '{$object->name()}' === '{$pickedObject->name()}': ");
                            continue;
                        }

                        $localMapping = $object->dstPortMapping();
                        PH::print_stdout( "    - adding the following ports to first service: " . $localMapping->mappingToText() . "" );
                        if( $this->action === "merge" )
                        {
                            $localMapping->mergeWithMapping($pickedObject->dstPortMapping());

                            if( $this->apiMode )
                            {
                                if( $pickedObject->isTcp() )
                                {
                                    $tmp_string = str_replace("tcp/", "", $localMapping->tcpMappingToText());
                                    $pickedObject->API_setDestPort( $tmp_string );
                                }
                                else
                                {
                                    $tmp_string = str_replace("udp/", "", $localMapping->udpMappingToText());
                                    $pickedObject->API_setDestPort( $tmp_string );
                                }

                                PH::print_stdout("    - removing '{$object->name()}' from places where it's used:");
                                $object->API_removeWhereIamUsed(TRUE, 7);
                                $object->owner->API_remove($object);
                                $countRemoved++;
                            }
                            else
                            {
                                if( $pickedObject->isTcp() )
                                {
                                    $tmp_string = str_replace("tcp/", "", $localMapping->tcpMappingToText());
                                    $pickedObject->setDestPort($tmp_string);
                                }
                                else
                                {
                                    $tmp_string = str_replace("udp/", "", $localMapping->udpMappingToText());
                                    $pickedObject->setDestPort( $tmp_string );
                                }


                                PH::print_stdout("    - removing '{$object->name()}' from places where it's used:");
                                $object->removeWhereIamUsed(TRUE, 7);
                                $object->owner->remove($object);
                                $countRemoved++;
                            }
                        }

                        if( $this->mergeCountLimit !== FALSE && $countRemoved >= $this->mergeCountLimit )
                        {
                            PH::print_stdout( "\n *** STOPPING MERGE OPERATIONS NOW SINCE WE REACH mergeCountLimit ({$this->mergeCountLimit})" );
                            break 2;
                        }

                    }
                    PH::print_stdout( "   * final mapping for service '{$pickedObject->name()}': {$pickedObject->getDestPort()}" );

                    PH::print_stdout();
                }
            else derr("unsupported use case");


            PH::print_stdout( "\n\nDuplicates removal is now done. Number of objects after cleanup: '{$store->countServices()}' (removed {$countRemoved} services)\n" );

        }
    }

    function servicePickedObjectValidation( $index, $object, $pickedObject )
    {
        /** @var Service $object */
        /** @var Service $pickedObject */

        $skipped = false;
        if( $object->protocol() != $pickedObject->protocol() )
        {
            $text = "    - object '{$object->name()}' cannot be merged because of different service protocol";
            $text .="  object protocol value: " . $object->protocol() . " | pickedObject protocol value: " . $pickedObject->protocol();
            PH::print_stdout( $text );
            $this->skippedObject( $index, $object, $pickedObject);
            $skipped = true;
        }
        elseif( !$object->srcPortMapping()->equals($pickedObject->srcPortMapping()) && $this->dupAlg == 'samedstsrcports' )
        {
            $text = "    - object '{$object->name()}' cannot be merged because of different SRC port information";
            $text .= "  object value: " . $object->srcPortMapping()->mappingToText() . " | pickedObject value: " . $pickedObject->srcPortMapping()->mappingToText();
            PH::print_stdout( $text );
            $this->skippedObject( $index, $object, $pickedObject);
            $skipped = true;
        }
        elseif( $object->getOverride() != $pickedObject->getOverride() )
        {
            $text = "    - object '{$object->name()}' cannot be merged because of different timeout Override information";
            $text .="  object timeout value: " . $object->getOverride() . " | pickedObject timeout value: " . $pickedObject->getOverride();
            PH::print_stdout( $text );
            $this->skippedObject( $index, $object, $pickedObject);
            $skipped = true;
        }

        return $skipped;
    }

    function tag_merging()
    {
        foreach( $this->location_array as $tmp_location )
        {
            $store = null;
            $findLocation = null;
            $parentStore = null;
            $childDeviceGroups = null;
            $this->locationSettings( $tmp_location, "Tag", $store,$findLocation,$parentStore,$childDeviceGroups);


            //
            // Building a hash table of all tag objects with same value
            //
            if( $this->upperLevelSearch )
                $objectsToSearchThrough = $store->nestedPointOfView();
            else
                $objectsToSearchThrough = $store->tags();

            $hashMap = array();
            $NamehashMap = array();
            $child_hashMap = array();
            $child_NamehashMap = array();
            $upperHashMap = array();
            $upper_NamehashMap = array();
            if( $this->dupAlg == 'samecolor' || $this->dupAlg == 'identical' || $this->dupAlg == 'samename' )
            {
                //todo: childDG/childDG to parentDG merge is always done; should it not combined to upperLevelSearch value?
                foreach( $childDeviceGroups as $dg )
                {
                    /** @var DeviceGroup $dg */
                    foreach( $dg->tagStore->tags() as $object )
                    {
                        if( !$object->isTag() )
                            continue;
                        if( $object->isTmp() )
                            continue;

                        if( $this->excludeFilter !== null && $this->excludeFilter->matchSingleObject(array('object' => $object, 'nestedQueries' => &$nestedQueries)) )
                            continue;

                        $value = $object->getColor();
                        $value = $object->name();

                        #PH::print_stdout( "add objNAME: " . $object->name() . " DG: " . $object->owner->owner->name() . "" );
                        $child_hashMap[$value][] = $object;
                        $child_NamehashMap[$object->name()][] = $object;
                    }
                }

                foreach( $objectsToSearchThrough as $object )
                {
                    if( !$object->isTag() )
                        continue;
                    if( $object->isTmp() )
                        continue;

                    if( $this->excludeFilter !== null && $this->excludeFilter->matchSingleObject(array('object' => $object, 'nestedQueries' => &$nestedQueries)) )
                        continue;

                    $skipThisOne = FALSE;

                    // Object with descendants in lower device groups should be excluded
                    if( $this->pan->isPanorama() && $object->owner === $store )
                    {
                        //do something
                    }
                    elseif( $this->pan->isPanorama() && $object->owner === $store )
                    {
                        //do something
                    }

                    $value = $object->getColor();
                    $value = $object->name();

                    if( $object->owner === $store )
                    {
                        $hashMap[$value][] = $object;
                        if( $parentStore !== null )
                            $object->ancestor = self::findAncestor( $parentStore, $object, "tagStore");
                    }
                    else
                        $upperHashMap[$value][] = $object;
                }
            }
            elseif( $this->dupAlg == 'whereused' )
                foreach( $objectsToSearchThrough as $object )
                {
                    if( !$object->isTag() )
                        continue;
                    if( $object->isTmp() )
                        continue;

                    if( $object->countReferences() == 0 )
                        continue;

                    if( $this->excludeFilter !== null && $this->excludeFilter->matchSingleObject(array('object' => $object, 'nestedQueries' => &$nestedQueries)) )
                        continue;

                    #$value = $object->getRefHashComp() . $object->getNetworkValue();
                    $value = $object->getRefHashComp() . $object->name();
                    if( $object->owner === $store )
                    {
                        $hashMap[$value][] = $object;
                        if( $parentStore !== null )
                            $object->ancestor = self::findAncestor( $parentStore, $object, "tagStore");
                    }
                    else
                        $upperHashMap[$value][] = $object;
                }
            else derr("unsupported use case");

//
// Hashes with single entries have no duplicate, let's remove them
//
            $countConcernedObjects = 0;
            self::removeSingleEntries( $hashMap, $child_hashMap, $upperHashMap, $countConcernedObjects);

            $countConcernedChildObjects = 0;
            self::removeSingleEntries( $child_hashMap, $hashMap, $upperHashMap, $countConcernedChildObjects);


            PH::print_stdout( " - found " . count($hashMap) . " duplicates values totalling {$countConcernedObjects} tag objects which are duplicate" );

            PH::print_stdout( " - found " . count($child_hashMap) . " duplicates childDG values totalling {$countConcernedChildObjects} tag objects which are duplicate" );


            PH::print_stdout( "\n\nNow going after each duplicates for a replacement" );

            $countChildRemoved = 0;
            $countChildCreated = 0;
            foreach( $child_hashMap as $index => &$hash )
            {
                PH::print_stdout();
                PH::print_stdout( " - value '{$index}'" );


                $pickedObject = $this->PickObject( $hash);

                //Todo: swaschkut 20241124 bring in hash map validation as for other objects types

                $tmp_DG_name = $store->owner->name();
                if( $tmp_DG_name == "" )
                    $tmp_DG_name = 'shared';

                /** @var Tag $tmp_tag */
                $tmpPickedName = $pickedObject->name();
                TAG::revertreplaceNamewith($tmpPickedName);
                $tmp_tag = $store->find( $tmpPickedName );
                if( $tmp_tag == null )
                {
                    if( isset( $child_NamehashMap[ $pickedObject->name() ] ) )
                    {
                        $exit = false;
                        $exitObject = null;
                        foreach( $child_NamehashMap[ $pickedObject->name() ] as $obj )
                        {
                            //Todo: validate if this is correct; expectation is not same color swaschkut 20230525
                            if( !$obj->sameValue($pickedObject) ) //true if same color, false if different color
                            {
                                if( $this->dupAlg !== 'samename' )
                                {
                                    $exit = true;
                                    $exitObject = $obj;
                                }
                            }
                        }

                        if( $exit )
                        {
                            PH::print_stdout( "   * SKIP: no creation of object in DG: '".$tmp_DG_name."' as object with same name '{$exitObject->name()}' and different value exist at childDG level" );
                            $this->skippedObject( $index, $pickedObject, $exitObject);
                            continue;
                        }
                    }
                    PH::print_stdout( "   * create object in DG: '".$tmp_DG_name."' : '".$tmpPickedName."'" );

                    if( $this->action === "merge" )
                    {
                        $tmp_tag = $store->createTag($tmpPickedName );
                        $tmp_tag->setColor( $pickedObject->getColor() );

                        /** @var TagStore $store */
                        if( $this->apiMode )
                            $tmp_tag->API_sync();

                        $tmpPickedName = $tmp_tag->name();
                        TAG::replaceNamewith($tmpPickedName);
                        $value = $tmpPickedName;
                        $hashMap[$value][] = $tmp_tag;
                    }
                    else
                        $tmp_tag = "[".$tmp_DG_name."] - ".$pickedObject->name()." {new}";

                    $countChildCreated++;
                }
                else
                {
                    if( $tmp_tag->equals( $pickedObject ) )
                    {
                        PH::print_stdout( "   * keeping object '{$tmp_tag->_PANC_shortName()}'" );
                    }
                    else
                    {
                        PH::print_stdout( "    - SKIP: object name '{$pickedObject->_PANC_shortName()}' [with value '{$pickedObject->getColor()}'] is not IDENTICAL to object name: '{$tmp_tag->_PANC_shortName()}' [with value '{$tmp_tag->getColor()}'] " );
                        $this->skippedObject( $index, $pickedObject, $tmp_tag);
                        continue;
                    }
                }


                // Merging loop finally!
                foreach( $hash as $objectIndex => $object )
                {
                    PH::print_stdout("    - replacing '{$object->_PANC_shortName()}' ...");
                    #$object->__replaceWhereIamUsed($this->apiMode, $tmp_tag, TRUE, 5);
                    if( $this->action === "merge" )
                        $object->replaceMeGlobally($tmp_tag, $this->apiMode);
                    #$object->merge_tag_description_to($tmp_tag, $this->apiMode);

                    PH::print_stdout("    - deleting '{$object->_PANC_shortName()}'");
                    self::deletedObject( $index, $tmp_tag, $object);

                    if( $this->action === "merge" )
                    {
                        if( $this->apiMode )
                            $object->owner->API_removeTag($object);
                        else
                            $object->owner->removeTag($object);
                    }
                    $countChildRemoved++;
                }
            }

            if( count( $child_hashMap ) >0 )
                PH::print_stdout( "\n\nDuplicates ChildDG removal is now done. Number of objects after cleanup: '{$store->count()}' (removed/created {$countChildRemoved}/{$countChildCreated} tags)\n" );

            $countRemoved = 0;
            foreach( $hashMap as $index => &$hash )
            {
                PH::print_stdout();
                PH::print_stdout( " - name '{$index}'" );


                $pickedObject = $this->hashMapPickfilter( $upperHashMap, $index, $hash );

                //Todo: swaschkut 20241124 bring in hashmap validation in same way as for address/service

                // Merging loop finally!
                foreach( $hash as $objectIndex => $object )
                {
                    /** @var Tag $object */

                    //Todo: swaschkut 20241124 bring in hash map validation as for other objects types

                    if( isset($object->ancestor) )
                    {
                        $ancestor = $object->ancestor;
                        $ancestor_different_color = "";

                        if( !$ancestor->isTag() )
                        {
                            PH::print_stdout("    - SKIP: object name '{$object->_PANC_shortName()}' has one ancestor which is not TAG object");
                            continue;
                        }

                        /** @var Tag $ancestor */
                        ##if( $this->upperLevelSearch && !$ancestor->isGroup() && !$ancestor->isTmpAddr() && ($ancestor->isType_ipNetmask() || $ancestor->isType_ipRange() || $ancestor->isType_FQDN()) )
                        if( $this->upperLevelSearch && !$ancestor->isTmp() )
                        {
                            if( $object->sameValue($ancestor) || $this->dupAlg == 'samename' ) //same color
                            {
                                if( $this->dupAlg == 'identical' )
                                    if( $pickedObject->name() != $ancestor->name() )
                                    {
                                        PH::print_stdout("    - SKIP: object name '{$object->_PANC_shortName()}' is not IDENTICAL to object name from upperlevel '{$pickedObject->_PANC_shortName()}' ");
                                        continue;
                                    }

                                $text = "    - object '{$object->name()}' merged with its ancestor, deleting: " . $object->_PANC_shortName();
                                self::deletedObject( $index, $ancestor, $object);

                                if( $this->action === "merge" )
                                {
                                    $object->replaceMeGlobally($ancestor, $this->apiMode);
                                    if( $this->apiMode )
                                        $object->owner->API_removeTag($object);
                                    else
                                        $object->owner->removeTag($object);
                                }

                                PH::print_stdout($text);

                                $text = "         ancestor name: '{$ancestor->name()}' DG: ";
                                if( $ancestor->owner->owner->name() == "" ) $text .= "'shared'";
                                else $text .= "'{$ancestor->owner->owner->name()}'";
                                $text .= "  color: '{$ancestor->getColor()}' ";
                                PH::print_stdout($text);

                                if( $pickedObject === $object )
                                    $pickedObject = $ancestor;

                                $countRemoved++;

                                if( $this->mergeCountLimit !== FALSE && $countRemoved >= $this->mergeCountLimit )
                                {
                                    PH::print_stdout("\n *** STOPPING MERGE OPERATIONS NOW SINCE WE REACHED mergeCountLimit ({$this->mergeCountLimit})");
                                    break 2;
                                }

                                continue;
                            }
                            else
                                $ancestor_different_color = "with different color";


                        }
                        PH::print_stdout("    - object '{$object->name()}' cannot be merged because it has an ancestor " . $ancestor_different_color . "");

                        $text = "         ancestor name: '{$ancestor->name()}' DG: ";
                        if( $ancestor->owner->owner->name() == "" ) $text .= "'shared'";
                        else $text .= "'{$ancestor->owner->owner->name()}'";
                        $text .= "  color: '{$ancestor->getColor()}' ";
                        PH::print_stdout($text);

                        if( $this->upperLevelSearch )
                        {
                            $tmpstring = "|->ERROR ancestor: '" . $object->_PANC_shortName() . "  color: '{$object->getColor()}' "."' cannot be merged. | ".$text;
                            $this->skippedObject( $index, $object, $ancestor, $tmpstring);
                        }
                        else
                            $tmpstring = "|-> ancestor: '" . $object->_PANC_shortName() . "' you did not allow to merged";
                        self::deletedObjectSetRemoved( $index, $tmpstring );

                        continue;
                    }

                    if( $object === $pickedObject )
                    {
                        #PH::print_stdout("    - SKIPPED: '{$object->name()}' === '{$pickedObject->name()}': ");
                        continue;
                    }

                    if( $this->dupAlg != 'identical' )
                    {
                        PH::print_stdout("    - replacing '{$object->_PANC_shortName()}' ...");
                        if( $this->action === "merge" )
                        {
                            #mwarning("implementation needed for TAG");
                            //Todo;SWASCHKUT
                            #$object->__replaceWhereIamUsed($this->apiMode, $pickedObject, TRUE, 5);
                            $object->replaceMeGlobally($pickedObject, $this->apiMode);
                        }


                        PH::print_stdout("    - deleting '{$object->_PANC_shortName()}'");
                        self::deletedObject( $index, $pickedObject, $object);

                        if( $this->action === "merge" )
                        {
                            if( $this->apiMode )
                                $object->owner->API_removeTag($object);
                            else
                                $object->owner->removeTag($object);
                        }

                        $countRemoved++;

                        if( $this->mergeCountLimit !== FALSE && $countRemoved >= $this->mergeCountLimit )
                        {
                            PH::print_stdout("\n *** STOPPING MERGE OPERATIONS NOW SINCE WE REACHED mergeCountLimit ({$this->mergeCountLimit})");
                            break 2;
                        }
                    }
                    else
                        PH::print_stdout("    - SKIP: object name '{$object->_PANC_shortName()}' is not IDENTICAL");
                }

            }

            PH::print_stdout( "\n\nDuplicates removal is now done. Number of objects after cleanup: '{$store->count()}' (removed {$countRemoved} tags)\n" );

        }
    }

    function custom_url_category_merging()
    {
        $objectType = "Custom-Url-Category";

        foreach( $this->location_array as $tmp_location )
        {
            $store = null;
            $findLocation = null;
            $parentStore = null;
            $childDeviceGroups = null;
            $this->locationSettings( $tmp_location, $objectType, $store,$findLocation,$parentStore,$childDeviceGroups);


            //
            // Building a hash table of all tag objects with same value
            //
            /** @var SecurityProfileStore $store */
            if( $this->upperLevelSearch )
                $objectsToSearchThrough = $store->nestedPointOfView();
            else
            {
                $objectsToSearchThrough = $store->getAll();
            }


            $hashMap = array();
            $child_hashMap = array();
            $child_NamehashMap = array();
            $upperHashMap = array();
            if( $this->dupAlg == 'samevalue' || $this->dupAlg == 'identical' || $this->dupAlg == 'samename' )
            {
                //todo: childDG/childDG to parentDG merge is always done; should it not combined to upperLevelSearch value?
                foreach( $childDeviceGroups as $dg )
                {
                    /** @var DeviceGroup $dg */
                    foreach( $dg->customURLProfileStore->securityProfiles() as $object )
                    {
                        /** @var customURLProfile $object */


                        #if( !$object->isCustomURL() )
                        if( get_class($object) !== "customURLProfile" )
                            continue;
                        /*
                        if( $object->isTmp() )
                            continue;
                        */

                        if( $this->excludeFilter !== null && $this->excludeFilter->matchSingleObject(array('object' => $object, 'nestedQueries' => &$nestedQueries)) )
                            continue;

                        $value = '';
                        $members = $object->getmembers();
                        foreach( $members as $member )
                            $value .= './.' . $member;

                        #PH::print_stdout( "add objNAME: " . $object->name() . " DG: " . $object->owner->owner->name() . "" );
                        $child_hashMap[$value][] = $object;
                        $child_NamehashMap[$object->name()][] = $object;
                    }
                }

                foreach( $objectsToSearchThrough as $object )
                {


                    #if( !$object->isCustomURL() )
                    if( get_class($object) !== "customURLProfile" )
                        continue;
                    /*
                    if( $object->isTmp() )
                        continue;
                    */

                    if( $this->excludeFilter !== null && $this->excludeFilter->matchSingleObject(array('object' => $object, 'nestedQueries' => &$nestedQueries)) )
                        continue;

                    $skipThisOne = FALSE;

                    // Object with descendants in lower device groups should be excluded
                    if( $this->pan->isPanorama() && $object->owner === $store )
                    {
                        //do something
                    }
                    elseif( $this->pan->isPanorama() && $object->owner === $store )
                    {
                        //do something
                    }


                    $value = '';
                    $members = $object->getmembers();
                    foreach( $members as $member )
                        $value .= './.' . $member;

                    if( $object->owner === $store )
                    {
                        $hashMap[$value][] = $object;
                        if( $parentStore !== null )
                            $object->ancestor = self::findAncestor( $parentStore, $object, "customURLProfileStore");
                    }
                    else
                        $upperHashMap[$value][] = $object;
                }
            }
            elseif( $this->dupAlg == 'whereused' )
                foreach( $objectsToSearchThrough as $object )
                {
                    if( !$object->isTag() )
                        continue;
                    if( $object->isTmp() )
                        continue;

                    if( $object->countReferences() == 0 )
                        continue;

                    if( $this->excludeFilter !== null && $this->excludeFilter->matchSingleObject(array('object' => $object, 'nestedQueries' => &$nestedQueries)) )
                        continue;

                    #$value = $object->getRefHashComp() . $object->getNetworkValue();
                    $value = $object->getRefHashComp() . $object->name();
                    if( $object->owner === $store )
                    {
                        $hashMap[$value][] = $object;
                        if( $parentStore !== null )
                            $object->ancestor = self::findAncestor( $parentStore, $object, "customURLProfileStore");
                    }
                    else
                        $upperHashMap[$value][] = $object;
                }
            else derr("unsupported use case");

            //
            // Hashes with single entries have no duplicate, let's remove them
            //
            $countConcernedObjects = 0;
            self::removeSingleEntries( $hashMap, $child_hashMap, $upperHashMap, $countConcernedObjects);

            $countConcernedChildObjects = 0;
            self::removeSingleEntries( $child_hashMap, $hashMap, $upperHashMap, $countConcernedChildObjects);


            PH::print_stdout( " - found " . count($hashMap) . " duplicates values totalling {$countConcernedObjects} custom-url-category objects which are duplicate" );

            PH::print_stdout( " - found " . count($child_hashMap) . " duplicates childDG values totalling {$countConcernedChildObjects} custom-url-category objects which are duplicate" );


            PH::print_stdout( "\n\nNow going after each duplicates for a replacement" );

            $countChildRemoved = 0;
            $countChildCreated = 0;


            foreach( $child_hashMap as $index => &$hash )
            {
                PH::print_stdout();
                PH::print_stdout( " - value '{$index}'" );


                $pickedObject = $this->PickObject( $hash);

                //Todo: swaschkut 20241124 bring in hash map validation as for other objects types

                $tmp_DG_name = $store->owner->name();
                if( $tmp_DG_name == "" )
                    $tmp_DG_name = 'shared';

                $tmp_tag = $store->find( $pickedObject->name() );
                if( $tmp_tag == null )
                {
                    if( isset( $child_NamehashMap[ $pickedObject->name() ] ) )
                    {
                        $exit = false;
                        $exitObject = null;
                        foreach( $child_NamehashMap[ $pickedObject->name() ] as $obj )
                        {
                            if( !$obj->sameValue($pickedObject) )
                            {
                                $exit = true;
                                $exitObject = $obj;
                            }
                        }

                        if( $exit )
                        {
                            $stringSkippedReason = $pickedObject->displayValueDiff($exitObject, 7, true);
                            PH::print_stdout( "   * SKIP: no creation of object in DG: '".$tmp_DG_name."' as object with same name '{$exitObject->name()}' and different value exist at childDG level" );
                            $this->skippedObject( $index, $pickedObject, $exitObject, $stringSkippedReason);
                            continue;
                        }
                    }
                    PH::print_stdout( "   * create object in DG: '".$tmp_DG_name."' : '".$pickedObject->name()."'" );

                    if( $this->action === "merge" )
                    {
                        $tmp_tag = $store->newCustomSecurityProfileURL($pickedObject->name() );
                        foreach( $pickedObject->getmembers() as $member )
                            $tmp_tag->addMember( $member );

                        if( $this->apiMode )
                            $tmp_tag->API_sync();

                        $value = $tmp_tag->name();
                        $hashMap[$value][] = $tmp_tag;
                    }
                    else
                        $tmp_tag = "[".$tmp_DG_name."] - ".$pickedObject->name()." {new}";

                    $countChildCreated++;
                }
                else
                {
                    if( $tmp_tag->equals( $pickedObject ) )
                    {
                        PH::print_stdout( "   * keeping object '{$tmp_tag->_PANC_shortName()}'" );
                    }
                    else
                    {
                        $stringSkippedReason = $pickedObject->displayValueDiff($tmp_tag, 7, true);
                        PH::print_stdout( "    - SKIP: object name '{$pickedObject->_PANC_shortName()}' [with value '".implode("./.",$pickedObject->getmembers())."'] is not IDENTICAL to object name: '{$tmp_tag->_PANC_shortName()}' [with value '".implode("./.",$tmp_tag->getmembers())."'] " );
                        $this->skippedObject( $index, $pickedObject, $tmp_tag, $stringSkippedReason);
                        continue;
                    }
                }


                // Merging loop finally!
                foreach( $hash as $objectIndex => $object )
                {
                    //Todo: swaschkut 20241124 bring in hash map validation as for other objects types

                    PH::print_stdout("    - replacing '{$object->_PANC_shortName()}' ...");

                    if( $this->action === "merge" )
                    {
                        #$object->__replaceWhereIamUsed($this->apiMode, $tmp_tag, TRUE, 5);
                        $object->replaceMeGlobally($tmp_tag, $this->apiMode);
                        /*
                         * - SecurityProfileStore:URL / URLProfile:
                         *
                         * //replace it the same way:
                         * - RuleStore:Security / SecurityRule:XYZ / UrlCategoryRuleContainer:ABC
                         * - RuleStore:Decryption / DecryptionRule:XYZ / UrlCategoryRuleContainer:ABC
                         */
                    }

                    PH::print_stdout("    - deleting '{$object->_PANC_shortName()}'");
                    self::deletedObject( $index, $tmp_tag, $object);

                    if( $this->action === "merge" )
                    {
                        if( $this->apiMode )
                            $object->owner->API_removeSecurityProfile($object);
                        else
                            $object->owner->removeSecurityProfile($object);
                    }
                    $countChildRemoved++;
                }
            }

            if( count( $child_hashMap ) >0 )
                PH::print_stdout( "\n\nDuplicates ChildDG removal is now done. Number of objects after cleanup: '{$store->count()}' (removed/created {$countChildRemoved}/{$countChildCreated} customURLcategory)\n" );

            $countRemoved = 0;
            foreach( $hashMap as $index => &$hash )
            {
                PH::print_stdout();
                PH::print_stdout( " - name '{$index}'" );


                $pickedObject = $this->hashMapPickfilter( $upperHashMap, $index, $hash );

                //Todo: swaschkut 20241124 bring in hashmap validation as for other objects types

                // Merging loop finally!
                foreach( $hash as $objectIndex => $object )
                {
                    /** @var customURLProfile $object */

                    //Todo: swaschkut 20241124 bring in hash map validation as for other objects types

                    if( isset($object->ancestor) )
                    {
                        $ancestor = $object->ancestor;
                        $ancestor_different_color = "";

                        if( get_class($ancestor) !== "customURLProfile" )
                        {
                            PH::print_stdout("    - SKIP: object name '{$object->_PANC_shortName()}' has one ancestor which is not customURLProfile object");
                            continue;
                        }

                        /** @var customURLProfile $ancestor */
                        if( $this->upperLevelSearch &&  get_class($ancestor) === "customURLProfile" )
                        {
                            //add addmissingobjects
                            if( $this->addMissingObjects )
                            {
                                if( !$object->sameValue($ancestor) )
                                    $this->customURLcategoryGetValueDiff( $ancestor, $object, true );
                            }

                            if( $object->sameValue($ancestor) || $this->dupAlg == 'samename' ) //same color
                            {
                                if( $this->dupAlg == 'identical' )
                                    if( $pickedObject->name() != $ancestor->name() )
                                    {
                                        PH::print_stdout("    - SKIP: object name '{$object->_PANC_shortName()}' is not IDENTICAL to object name from upperlevel '{$pickedObject->_PANC_shortName()}' ");
                                        continue;
                                    }

                                $text = "    - object '{$object->name()}' merged with its ancestor, deleting: " . $object->_PANC_shortName();
                                self::deletedObject( $index, $ancestor, $object);

                                if( $this->action === "merge" )
                                {
                                    $object->replaceMeGlobally($ancestor, $this->apiMode);
                                    if( $this->apiMode )
                                        $object->owner->API_removeSecurityProfile($object);
                                    else
                                        $object->owner->removeSecurityProfile($object);
                                }

                                PH::print_stdout($text);

                                $text = "         ancestor name: '{$ancestor->name()}' DG: ";
                                if( $ancestor->owner->owner->name() == "" ) $text .= "'shared'";
                                else $text .= "'{$ancestor->owner->owner->name()}'";
                                $text .= "  Value: '".implode("./.",$ancestor->getmembers())."' ";
                                PH::print_stdout($text);

                                if( $pickedObject === $object )
                                    $pickedObject = $ancestor;

                                $countRemoved++;

                                if( $this->mergeCountLimit !== FALSE && $countRemoved >= $this->mergeCountLimit )
                                {
                                    PH::print_stdout("\n *** STOPPING MERGE OPERATIONS NOW SINCE WE REACHED mergeCountLimit ({$this->mergeCountLimit})");
                                    break 2;
                                }

                                continue;
                            }
                            else
                                $ancestor_different_color = "with different value";

                            if( $this->addMissingObjects )
                                continue;
                        }

                        PH::print_stdout("    - object '{$object->name()}' cannot be merged because it has an ancestor " . $ancestor_different_color . "");
                        $ancestor->displayValueDiff( $object, 7);
                        $tmp_skippedReason = $ancestor->displayValueDiff( $object, 7, true);

                        $text = "         ancestor name: '{$ancestor->name()}' DG: ";
                        if( $ancestor->owner->owner->name() == "" ) $text .= "'shared'";
                        else $text .= "'{$ancestor->owner->owner->name()}'";
                        $text .= "  Value: '".implode("./.",$ancestor->getmembers())."' ";
                        PH::print_stdout($text);
                        $this->skippedObject( $index, $object, $ancestor, $tmp_skippedReason);

                        if( $this->upperLevelSearch )
                        {
                            $tmpstring = "|->ERROR ancestor: '" . $object->_PANC_shortName() . "  value: '".implode("./.",$ancestor->getmembers())."' "."' cannot be merged. | ".$text;
                            $this->skippedObject( $index, $object, $ancestor, $tmpstring);
                        }
                        else
                            $tmpstring = "|-> ancestor: '" . $object->_PANC_shortName() . "' you did not allow to merged";
                        self::deletedObjectSetRemoved( $index, $tmpstring );

                        continue;
                    }

                    if( $object === $pickedObject )
                    {
                        #PH::print_stdout("    - SKIPPED: '{$object->name()}' === '{$pickedObject->name()}': ");
                        continue;
                    }

                    if( $this->dupAlg != 'identical' )
                    {
                        PH::print_stdout("    - replacing '{$object->_PANC_shortName()}' ...");
                        #$object->__replaceWhereIamUsed($this->apiMode, $pickedObject, TRUE, 5);
                        if( $this->action === "merge" )
                            $object->replaceMeGlobally($pickedObject, $this->apiMode);

                        PH::print_stdout("    - deleting '{$object->_PANC_shortName()}'");
                        self::deletedObject( $index, $pickedObject, $object);

                        if( $this->action === "merge" )
                        {
                            if( $this->apiMode )
                                $object->owner->API_removeSecurityProfile($object);
                            else
                                $object->owner->removeSecurityProfile($object);
                        }

                        $countRemoved++;

                        if( $this->mergeCountLimit !== FALSE && $countRemoved >= $this->mergeCountLimit )
                        {
                            PH::print_stdout("\n *** STOPPING MERGE OPERATIONS NOW SINCE WE REACHED mergeCountLimit ({$this->mergeCountLimit})");
                            break 2;
                        }
                    }
                    else
                        PH::print_stdout("    - SKIP: object name '{$object->_PANC_shortName()}' is not IDENTICAL");
                }

            }

            PH::print_stdout( "\n\nDuplicates removal is now done. Number of objects after cleanup: '{$store->count()}' (removed {$countRemoved} customURLcategory)\n" );

        }
    }

    /**
     * @param customURLProfile $ancestor
     * @param customURLProfile $object
     */
    function customURLcategoryGetValueDiff( $ancestor, $object, $display = false)
    {
        if( $display )
            $ancestor->displayValueDiff($object, 7);

        if( $this->addMissingObjects )
        {
            $diff = $ancestor->getValueDiff($object);

            if( count($diff['minus']) != 0 )
                foreach( $diff['minus'] as $d )
                {
                    /** @var string $d */

                    PH::print_stdout("      - adding objects to group: " . $d . "");
                    if( $this->action === "merge" )
                    {
                        if( $this->apiMode )
                        {
                            //
                            mwarning("API mode not implemented yet");
                            #$ancestor->API_addMember($d);
                        }
                        else
                            $ancestor->addMember($d);
                    }
                }

            if( count($diff['plus']) != 0 )
                foreach( $diff['plus'] as $d )
                {
                    /** @var Service|ServiceGroup $d */
                    //TMP usage to clean DG level customURLcategory up
                    if( $this->action === "merge" )
                        $object->addMember($d);
                }
        }
    }

    function object_merging( $objectType )
    {
        $objectType = "Application";

        foreach( $this->location_array as $tmp_location )
        {
            $store = null;
            $findLocation = null;
            $parentStore = null;
            $childDeviceGroups = null;
            $this->locationSettings( $tmp_location, $objectType, $store,$findLocation,$parentStore,$childDeviceGroups);


            //
            // Building a hash table of all tag objects with same value
            //
            if( $this->upperLevelSearch )
                $objectsToSearchThrough = $store->nestedPointOfView();
            else
            {
                if( $objectType == "Application" )
                {
                    //get all Application()
                    #$objectsToSearchThrough = $store->a;
                }

            }


            $hashMap = array();
            $child_hashMap = array();
            $child_NamehashMap = array();
            $upperHashMap = array();
            if( $this->dupAlg == 'samecolor' || $this->dupAlg == 'identical' || $this->dupAlg == 'samename' )
            {
                //todo: childDG/childDG to parentDG merge is always done; should it not combined to upperLevelSearch value?
                foreach( $childDeviceGroups as $dg )
                {
                    /** @var DeviceGroup $dg */
                    foreach( $dg->tagStore->tags() as $object )
                    {
                        if( !$object->isTag() )
                            continue;
                        if( $object->isTmp() )
                            continue;

                        if( $this->excludeFilter !== null && $this->excludeFilter->matchSingleObject(array('object' => $object, 'nestedQueries' => &$nestedQueries)) )
                            continue;

                        $value = $object->getColor();
                        $value = $object->name();

                        #PH::print_stdout( "add objNAME: " . $object->name() . " DG: " . $object->owner->owner->name() . "" );
                        $child_hashMap[$value][] = $object;
                        $child_NamehashMap[$object->name()][] = $object;
                    }
                }

                foreach( $objectsToSearchThrough as $object )
                {
                    if( !$object->isTag() )
                        continue;
                    if( $object->isTmp() )
                        continue;

                    if( $this->excludeFilter !== null && $this->excludeFilter->matchSingleObject(array('object' => $object, 'nestedQueries' => &$nestedQueries)) )
                        continue;

                    $skipThisOne = FALSE;

                    // Object with descendants in lower device groups should be excluded
                    if( $this->pan->isPanorama() && $object->owner === $store )
                    {
                        //do something
                    }
                    elseif( $this->pan->isPanorama() && $object->owner === $store )
                    {
                        //do something
                    }

                    $value = $object->getColor();
                    $value = $object->name();

                    if( $object->owner === $store )
                    {
                        $hashMap[$value][] = $object;
                        if( $parentStore !== null )
                            $object->ancestor = self::findAncestor( $parentStore, $object, "tagStore");
                    }
                    else
                        $upperHashMap[$value][] = $object;
                }
            }
            elseif( $this->dupAlg == 'whereused' )
                foreach( $objectsToSearchThrough as $object )
                {
                    if( !$object->isTag() )
                        continue;
                    if( $object->isTmp() )
                        continue;

                    if( $object->countReferences() == 0 )
                        continue;

                    if( $this->excludeFilter !== null && $this->excludeFilter->matchSingleObject(array('object' => $object, 'nestedQueries' => &$nestedQueries)) )
                        continue;

                    #$value = $object->getRefHashComp() . $object->getNetworkValue();
                    $value = $object->getRefHashComp() . $object->name();
                    if( $object->owner === $store )
                    {
                        $hashMap[$value][] = $object;
                        if( $parentStore !== null )
                            $object->ancestor = self::findAncestor( $parentStore, $object, "tagStore");
                    }
                    else
                        $upperHashMap[$value][] = $object;
                }
            else derr("unsupported use case");

//
// Hashes with single entries have no duplicate, let's remove them
//
            $countConcernedObjects = 0;
            self::removeSingleEntries( $hashMap, $child_hashMap, $upperHashMap, $countConcernedObjects);

            $countConcernedChildObjects = 0;
            self::removeSingleEntries( $child_hashMap, $hashMap, $upperHashMap, $countConcernedChildObjects);


            PH::print_stdout( " - found " . count($hashMap) . " duplicates values totalling {$countConcernedObjects} tag objects which are duplicate" );

            PH::print_stdout( " - found " . count($child_hashMap) . " duplicates childDG values totalling {$countConcernedChildObjects} tag objects which are duplicate" );


            PH::print_stdout( "\n\nNow going after each duplicates for a replacement" );

            $countChildRemoved = 0;
            $countChildCreated = 0;
            foreach( $child_hashMap as $index => &$hash )
            {
                PH::print_stdout();
                PH::print_stdout( " - value '{$index}'" );


                $pickedObject = $this->PickObject( $hash);

                //Todo: swaschkut 20241124 bring in hash map validation as for other objects types

                $tmp_DG_name = $store->owner->name();
                if( $tmp_DG_name == "" )
                    $tmp_DG_name = 'shared';

                /** @var Tag $tmp_tag */
                $tmpPickedName = $pickedObject->name();
                TAG::revertreplaceNamewith($tmpPickedName);
                $tmp_tag = $store->find( $tmpPickedName );
                if( $tmp_tag == null )
                {
                    if( isset( $child_NamehashMap[ $pickedObject->name() ] ) )
                    {
                        $exit = false;
                        $exitObject = null;
                        foreach( $child_NamehashMap[ $pickedObject->name() ] as $obj )
                        {
                            if( !$obj->sameValue($pickedObject) ) //same color
                            {
                                $exit = true;
                                $exitObject = $obj;
                            }
                        }

                        if( $exit )
                        {
                            PH::print_stdout( "   * SKIP: no creation of object in DG: '".$tmp_DG_name."' as object with same name '{$exitObject->name()}' and different value exist at childDG level" );
                            $this->skippedObject( $index, $pickedObject, $exitObject);
                            continue;
                        }
                    }
                    PH::print_stdout( "   * create object in DG: '".$tmp_DG_name."' : '".$tmpPickedName."'" );

                    if( $this->action === "merge" )
                    {
                        $tmp_tag = $store->createTag($tmpPickedName );
                        $tmp_tag->setColor( $pickedObject->getColor() );

                        /** @var TagStore $store */
                        if( $this->apiMode )
                            $tmp_tag->API_sync();

                        $tmpPickedName = $tmp_tag->name();
                        TAG::replaceNamewith($tmpPickedName);
                        $value = $tmpPickedName;
                        $hashMap[$value][] = $tmp_tag;
                    }
                    else
                        $tmp_tag = "[".$tmp_DG_name."] - ".$pickedObject->name()." {new}";

                    $countChildCreated++;
                }
                else
                {
                    if( $tmp_tag->equals( $pickedObject ) )
                    {
                        PH::print_stdout( "   * keeping object '{$tmp_tag->_PANC_shortName()}'" );
                    }
                    else
                    {
                        PH::print_stdout( "    - SKIP: object name '{$pickedObject->_PANC_shortName()}' [with value '{$pickedObject->getColor()}'] is not IDENTICAL to object name: '{$tmp_tag->_PANC_shortName()}' [with value '{$tmp_tag->getColor()}'] " );
                        $this->skippedObject( $index, $pickedObject, $tmp_tag);
                        continue;
                    }
                }


                // Merging loop finally!
                foreach( $hash as $objectIndex => $object )
                {
                    //Todo: swaschkut 20241124 bring in hash map validation as for other objects types

                    PH::print_stdout("    - replacing '{$object->_PANC_shortName()}' ...");
                    #$object->__replaceWhereIamUsed($this->apiMode, $tmp_tag, TRUE, 5);
                    if( $this->action === "merge" )
                        $object->replaceMeGlobally($tmp_tag);
                    #$object->merge_tag_description_to($tmp_tag, $this->apiMode);

                    PH::print_stdout("    - deleting '{$object->_PANC_shortName()}'");
                    self::deletedObject( $index, $tmp_tag, $object);

                    if( $this->action === "merge" )
                    {
                        if( $this->apiMode )
                            $object->owner->API_removeTag($object);
                        else
                            $object->owner->removeTag($object);
                    }
                    $countChildRemoved++;
                }
            }

            if( count( $child_hashMap ) >0 )
                PH::print_stdout( "\n\nDuplicates ChildDG removal is now done. Number of objects after cleanup: '{$store->count()}' (removed/created {$countChildRemoved}/{$countChildCreated} tags)\n" );

            $countRemoved = 0;
            foreach( $hashMap as $index => &$hash )
            {
                PH::print_stdout();
                PH::print_stdout( " - name '{$index}'" );


                $pickedObject = $this->hashMapPickfilter( $upperHashMap, $index, $hash );

                //Todo: swaschkut 20241124 bring in hash map validation as for other objects types

                // Merging loop finally!
                foreach( $hash as $objectIndex => $object )
                {
                    /** @var Tag $object */

                    //Todo: swaschkut 20241124 bring in hash map validation as for other objects types

                    if( isset($object->ancestor) )
                    {
                        $ancestor = $object->ancestor;
                        $ancestor_different_color = "";

                        if( !$ancestor->isTag() )
                        {
                            PH::print_stdout("    - SKIP: object name '{$object->_PANC_shortName()}' has one ancestor which is not TAG object");
                            continue;
                        }

                        /** @var Tag $ancestor */
                        ##if( $this->upperLevelSearch && !$ancestor->isGroup() && !$ancestor->isTmpAddr() && ($ancestor->isType_ipNetmask() || $ancestor->isType_ipRange() || $ancestor->isType_FQDN()) )
                        if( $this->upperLevelSearch && !$ancestor->isTmp() )
                        {
                            if( $object->sameValue($ancestor) || $this->dupAlg == 'samename' ) //same color
                            {
                                if( $this->dupAlg == 'identical' )
                                    if( $pickedObject->name() != $ancestor->name() )
                                    {
                                        PH::print_stdout("    - SKIP: object name '{$object->_PANC_shortName()}' is not IDENTICAL to object name from upperlevel '{$pickedObject->_PANC_shortName()}' ");
                                        continue;
                                    }

                                $text = "    - object '{$object->name()}' merged with its ancestor, deleting: " . $object->_PANC_shortName();
                                self::deletedObject( $index, $ancestor, $object);

                                if( $this->action === "merge" )
                                {
                                    $object->replaceMeGlobally($ancestor);
                                    if( $this->apiMode )
                                        $object->owner->API_removeTag($object);
                                    else
                                        $object->owner->removeTag($object);
                                }

                                PH::print_stdout($text);

                                $text = "         ancestor name: '{$ancestor->name()}' DG: ";
                                if( $ancestor->owner->owner->name() == "" ) $text .= "'shared'";
                                else $text .= "'{$ancestor->owner->owner->name()}'";
                                $text .= "  color: '{$ancestor->getColor()}' ";
                                PH::print_stdout($text);

                                if( $pickedObject === $object )
                                    $pickedObject = $ancestor;

                                $countRemoved++;

                                if( $this->mergeCountLimit !== FALSE && $countRemoved >= $this->mergeCountLimit )
                                {
                                    PH::print_stdout("\n *** STOPPING MERGE OPERATIONS NOW SINCE WE REACHED mergeCountLimit ({$this->mergeCountLimit})");
                                    break 2;
                                }

                                continue;
                            }
                            else
                                $ancestor_different_color = "with different color";


                        }
                        PH::print_stdout("    - object '{$object->name()}' cannot be merged because it has an ancestor " . $ancestor_different_color . "");

                        $text = "         ancestor name: '{$ancestor->name()}' DG: ";
                        if( $ancestor->owner->owner->name() == "" ) $text .= "'shared'";
                        else $text .= "'{$ancestor->owner->owner->name()}'";
                        $text .= "  color: '{$ancestor->getColor()}' ";
                        PH::print_stdout($text);

                        if( $this->upperLevelSearch )
                        {
                            $tmpstring = "|->ERROR ancestor: '" . $object->_PANC_shortName() . "  color: '{$object->getColor()}' "."' cannot be merged. | ".$text;
                            $this->skippedObject( $index, $object, $ancestor, $tmpstring);
                        }
                        else
                            $tmpstring = "|-> ancestor: '" . $object->_PANC_shortName() . "' you did not allow to merged";
                        self::deletedObjectSetRemoved( $index, $tmpstring );

                        continue;
                    }

                    if( $object === $pickedObject )
                    {
                        #PH::print_stdout("    - SKIPPED: '{$object->name()}' === '{$pickedObject->name()}': ");
                        continue;
                    }

                    if( $this->dupAlg != 'identical' )
                    {
                        PH::print_stdout("    - replacing '{$object->_PANC_shortName()}' ...");
                        mwarning("implementation needed for OBJECT");
                        //Todo;SWASCHKUT
                        #$object->__replaceWhereIamUsed($this->apiMode, $pickedObject, TRUE, 5);

                        PH::print_stdout("    - deleting '{$object->_PANC_shortName()}'");
                        self::deletedObject( $index, $pickedObject, $object);

                        if( $this->action === "merge" )
                        {
                            if( $this->apiMode )
                                $object->owner->API_removeTag($object);
                            else
                                $object->owner->removeTag($object);
                        }

                        $countRemoved++;

                        if( $this->mergeCountLimit !== FALSE && $countRemoved >= $this->mergeCountLimit )
                        {
                            PH::print_stdout("\n *** STOPPING MERGE OPERATIONS NOW SINCE WE REACHED mergeCountLimit ({$this->mergeCountLimit})");
                            break 2;
                        }
                    }
                    else
                        PH::print_stdout("    - SKIP: object name '{$object->_PANC_shortName()}' is not IDENTICAL");
                }

            }

            PH::print_stdout( "\n\nDuplicates removal is now done. Number of objects after cleanup: '{$store->count()}' (removed {$countRemoved} tags)\n" );

        }
    }

    function merger_final_step()
    {
        $this->save_our_work( true );

        if( $this->exportcsv )
        {
            PH::print_stdout(" * script was called with argument 'exportCSV' - please wait for calculation");

            $tmp_string = "value,kept(create),removed";
            foreach( $this->deletedObjects as $obj_index => $object_name )
            {
                if( isset($object_name['kept']) )
                    $tmp_kept = $object_name['kept'];
                else
                    $tmp_kept = "";
                $tmp_string .= $obj_index . "," . $tmp_kept . "," . $object_name['removed']."\n";
            }


            if( $this->exportcsvFile !== null )
            {
                self::exportCSVToHtml();
                self::exportCSVToHtml( true );
            }

            else
                PH::print_stdout( $tmp_string );
        }
    }

    function exportCSVToHtml( $skipped = FALSE)
    {
        if( !$skipped )
            $headers = '<th>ID</th><th>hash</th><th>kept (create)</th><th>removed</th>';
        else
            $headers = '<th>ID</th><th>hash</th><th>kept</th><th>not merged with</th><th>reason</th>';


        $lines = '';
        $encloseFunction = function ($value, $nowrap = TRUE) {
            if( is_string($value) )
                $output = htmlspecialchars($value);
            elseif( is_array($value) )
            {
                $output = '';
                $first = TRUE;
                foreach( $value as $subValue )
                {
                    if( !$first )
                    {
                        $output .= '<br />';
                    }
                    else
                        $first = FALSE;

                    if( is_string($subValue) )
                        $output .= htmlspecialchars($subValue);
                    else
                        $output .= htmlspecialchars($subValue->name());
                }
            }
            else
            {
                derr('unsupported: '.$value);
            }


            if( $nowrap )
                return '<td style="white-space: nowrap">' . $output . '</td>';

            return '<td>' . $output . '</td>';
        };

        $obj_Array = array();
        if( !$skipped )
        {
            if( isset($this->deletedObjects) )
                $obj_Array = $this->deletedObjects;
        }
        else
        {
            if( isset($this->skippedObjects) )
                $obj_Array = $this->skippedObjects;
        }


        $count = 0;
        foreach( $obj_Array as $index => $line )
            {
                $count++;

                if( $count % 2 == 1 )
                    $lines .= "<tr>\n";
                else
                    $lines .= "<tr bgcolor=\"#DDDDDD\">";

                $lines .= $encloseFunction( (string)$count );

                $tmp_array = explode( "./.", $index );
                $lines .= $encloseFunction( $tmp_array );
                #$lines .= $encloseFunction( (string)$index );

                if( isset( $line['kept'] ) )
                    $lines .= $encloseFunction( $line['kept'] );
                else
                    $lines .= $encloseFunction( "" );

                $removedArray = explode( "|", $line['removed'] );
                $lines .= $encloseFunction( $removedArray );

                if( isset( $line['reason'] ) && $skipped )
                {
                    $tmp_array = explode(PHP_EOL, $line['reason']);
                    $lines .= $encloseFunction( $tmp_array );
                }
                elseif( $skipped )
                    $lines .= $encloseFunction( "" );

                $lines .= "</tr>\n";

            }

        $content = file_get_contents(dirname(__FILE__) . '/../common/html/export-template.html');
        $content = str_replace('%TableHeaders%', $headers, $content);

        $content = str_replace('%lines%', $lines, $content);

        $jscontent = file_get_contents(dirname(__FILE__) . '/../common/html/jquery.min.js');
        $jscontent .= "\n";
        $jscontent .= file_get_contents(dirname(__FILE__) . '/../common/html/jquery.stickytableheaders.min.js');
        $jscontent .= "\n\$('table').stickyTableHeaders();\n";

        $content = str_replace('%JSCONTENT%', $jscontent, $content);

        if( PH::$shadow_json )
            PH::$JSON_OUT['exportcsv'] = $content;

        if( !$skipped )
            $filename = $this->exportcsvFile;
        else
            $filename = $this->exportcsvSkippedFile;
        file_put_contents($filename, $content);
    }

    private function deletedObject( $index, $keptOBJ, $removedOBJ)
    {
        if( is_object( $keptOBJ ) )
        {
            if( $keptOBJ->owner->owner->name() === "" )
                $tmpDG = "shared";
            else
                $tmpDG = $keptOBJ->owner->owner->name();
            $this->deletedObjects[$index]['kept'] = "[".$tmpDG. "] - ".$keptOBJ->name();
        }
        else
        {
            $this->deletedObjects[$index]['kept'] = $keptOBJ;
        }


        if( $removedOBJ->owner->owner->name() === "" )
            $tmpDG = "shared";
        else
            $tmpDG = $removedOBJ->owner->owner->name();

        if( !isset( $this->deletedObjects[$index]['removed'] ) )
        {
            $tmpstring = "[".$tmpDG. "] - ".$removedOBJ->name();
            if( get_class( $removedOBJ ) === "Address" && $removedOBJ->isType_TMP() )
                $tmpstring .= " (tmp)";

            $this->deletedObjects[$index]['removed'] = $tmpstring;
        }
        else
        {
            $tmpstring = "[".$tmpDG. "] - ".$removedOBJ->name();
            if( get_class( $removedOBJ ) === "Address" && $removedOBJ->isType_TMP() )
                $tmpstring .= " (tmp)";

            if( strpos( $this->deletedObjects[$index]['removed'], $tmpstring ) === FALSE )
                $this->deletedObjects[$index]['removed'] .= "|" . $tmpstring;
        }
    }

    private function skippedObject( $index, $keptOBJ, $removedOBJ, $reason = "")
    {
        if( is_object( $keptOBJ ) )
        {
            if( $keptOBJ->owner->owner->name() === "" )
                $tmpDG = "shared";
            else
                $tmpDG = $keptOBJ->owner->owner->name();
            $this->skippedObjects[$index]['kept'] = "[".$tmpDG. "] - ".$keptOBJ->name();
            if( get_class( $removedOBJ ) === "Address" )
            {
                if( get_class( $keptOBJ ) === "Address" )
                {
                    /** @var $keptOBJ Address */
                    $this->skippedObjects[$index]['kept'] .= "{value:".$keptOBJ->value()."}";
                }
                else
                    $this->skippedObjects[$index]['kept'] .= "{value:GROUP}";
            }
            elseif( get_class( $removedOBJ ) === "Service" )
            {
                if( get_class( $keptOBJ ) === "Service" )
                {
                    /** @var $keptOBJ Service */
                    $this->skippedObjects[$index]['kept'] .= " {prot:".$keptOBJ->protocol()."}{dport:".$keptOBJ->getDestPort()."}";
                    if( !empty($keptOBJ->getSourcePort()) )
                        $this->skippedObjects[$index]['kept'] .= "{sport:".$keptOBJ->getSourcePort()."}";
                    if( !empty($keptOBJ->getTimeout()) )
                        $this->skippedObjects[$index]['kept'] .= "{timeout:".$keptOBJ->getTimeout()."}";
                }
                else
                    $this->skippedObjects[$index]['kept'] .= "{value:GROUP}";
            }
            $this->skippedObjects[$index]['reason'] = $reason;
        }
        else
        {
            $this->skippedObjects[$index]['kept'] = $keptOBJ;
            $this->skippedObjects[$index]['reason'] = $reason;
        }

        if( is_object( $removedOBJ ) )
        {
            if( !isset( $removedOBJ->owner->owner ) )
            {
                $this->skippedObjects[$index]['removed'] = "1?????";
                return null;
            }

            if( $removedOBJ->owner->owner->name() === "" )
                $tmpDG = "shared";
            else
                $tmpDG = $removedOBJ->owner->owner->name();

            if( !isset($this->skippedObjects[$index]['removed']) )
            {
                $tmpstring = "[" . $tmpDG . "] - " . $removedOBJ->name();
                if( get_class($removedOBJ) === "Address" && $removedOBJ->isType_TMP() )
                    $tmpstring .= " (tmp)";

                $this->skippedObjects[$index]['removed'] = $tmpstring;
                if( get_class($removedOBJ) === "Address" )
                {
                    /** @var $keptOBJ Address */
                    $this->skippedObjects[$index]['removed'] .= "{value:" . $removedOBJ->value() . "}";
                }
                else if( get_class($removedOBJ) === "Service" )
                {
                    /** @var $keptOBJ Service */
                    $this->skippedObjects[$index]['removed'] .= "{prot:" . $removedOBJ->protocol() . "}{dport:" . $removedOBJ->getDestPort() . "}";
                    if( !empty($removedOBJ->getSourcePort()) )
                        $this->skippedObjects[$index]['removed'] .= "{sport:" . $removedOBJ->getSourcePort() . "}";
                    if( !empty($removedOBJ->getTimeout()) )
                        $this->skippedObjects[$index]['removed'] .= "{timeout:".$removedOBJ->getTimeout()."}";
                }
            }
            else
            {
                $tmpstring = "[" . $tmpDG . "] - " . $removedOBJ->name();
                if( get_class($removedOBJ) === "Address" && $removedOBJ->isType_TMP() )
                    $tmpstring .= " (tmp)";

                if( strpos($this->skippedObjects[$index]['removed'], $tmpstring) === FALSE )
                {
                    $this->skippedObjects[$index]['removed'] .= "|" . $tmpstring;
                    if( get_class($removedOBJ) === "Address" )
                    {
                        /** @var $keptOBJ Address */
                        $this->skippedObjects[$index]['removed'] .= "{value:" . $removedOBJ->value() . "}";
                    }
                    elseif( get_class($removedOBJ) === "Service" )
                    {
                        /** @var $keptOBJ Service */
                        $this->skippedObjects[$index]['removed'] .= "{prot:" . $removedOBJ->protocol() . "}{dport:" . $removedOBJ->getDestPort() . "}";
                        if( !empty($removedOBJ->getSourcePort()) )
                            $this->skippedObjects[$index]['removed'] .= "{sport:" . $removedOBJ->getSourcePort() . "}";
                        if( !empty($removedOBJ->getTimeout()) )
                            $this->skippedObjects[$index]['removed'] .= "{timeout:".$removedOBJ->getTimeout()."}";
                    }
                }
            }
        }
        else
        {
            $this->skippedObjects[$index]['removed'] = "2?????";
        }
    }

    private function deletedObjectSetRemoved( $index, $tmpstring )
    {
        if( !isset( $this->deletedObjects[$index]['removed'] ) )
            $this->deletedObjects[$index]['removed'] = "";

        $this->deletedObjects[$index]['removed'] .= $tmpstring;
    }

    private function removeSingleEntries( &$hashMap, $other_hashMap, $upperHashMap, &$countObjects = 0)
    {
        foreach( $hashMap as $index => &$hash )
        {
            if( count($hash) == 1 && !isset($upperHashMap[$index]) && !isset($other_hashMap[$index]) && !isset(reset($hash)->ancestor) )
                unset($hashMap[$index]);
            else
                $countObjects += count($hash);
        }
        unset($hash);
    }
}