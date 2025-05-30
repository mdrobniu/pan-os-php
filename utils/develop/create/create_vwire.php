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


set_include_path(dirname(__FILE__) . '/../' . PATH_SEPARATOR . get_include_path());
require_once dirname(__FILE__)."/../../../lib/pan_php_framework.php";
require_once dirname(__FILE__)."/../../../utils/lib/UTIL.php";

PH::print_stdout();
PH::print_stdout("***********************************************");
PH::print_stdout("*********** " . basename(__FILE__) . " UTILITY **************");
PH::print_stdout();


PH::print_stdout( "PAN-OS-PHP version: ".PH::frameworkVersion() );


$filePath = dirname(__FILE__)."/create_vwire.json";
if( file_exists( $filePath ) )
{
    $someJSON = file_get_contents( $filePath );
    // Convert JSON string to Array
    $vwireVariables = json_decode($someJSON, TRUE);
}
else
{
    $vwireVariables = array();
    $vlan_array = array( 0 => "100", 1 => "200", 2 => "300", 3 => "400", 4 => "500" );
    //no subinterfaces
    //$vlan_array = array();

    //AE can not be done on VM-Series
    $ae_solution = "false";

    $ae_solution_value = array();
    //ethernet Interface for AE
    //example: array("1/9","1/10") => ethernet1/9 and ethernet1/10
    $ae_solution_value['int1_array'] = array( 0 => "1/9", 1 => "1/10");
    $ae_solution_value['int2_array'] = array( 0 => "1/11", 1 => "1/11");
    //example: array(5,6) => ae5 and ae6
    #$ae_array = array( 5, 6 );
    $ae_solution_value['ae_array'] = array( 0 => "5", 1 => "6");

    $none_ae_solution_value = array();
    #$int1_array = array('1/3','1/4');
    #$int2_array = array();
    #$ae_array = array( 0 );
    $none_ae_solution_value['int1_array'] = array( 0 => "1/3", 1 => "1/4");
    $none_ae_solution_value['int2_array'] = array();
    $none_ae_solution_value['ae_array'] = array( 0 => "0");

    $vwireVariables['vlan_array'] = $vlan_array;
    $vwireVariables['ae_solution'] = $ae_solution;
    $vwireVariables['ae_solution_value'] = $ae_solution_value;
    $vwireVariables['none_ae_solution_value'] = $none_ae_solution_value;
}

$vlan_array = $vwireVariables['vlan_array'];


///////////////////////////////////////////////////////
///////////////////////////////////////////////////////
$ae_solution = $vwireVariables['ae_solution'];
if( $ae_solution === "false" )
    $ae_solution = false;
elseif( $ae_solution === "true" )
    $ae_solution = true;

if( $ae_solution )
{
    $int1_array = $vwireVariables['ae_solution_value']['int1_array'];
    $int2_array = $vwireVariables['ae_solution_value']['int2_array'];

    $ae_array = $vwireVariables['ae_solution_value']['ae_array'];

    $ae_interface_prefix = "ae";
    $tmp_int_type = "aggregate-group";
}
else
{
    $int1_array = $vwireVariables['none_ae_solution_value']['int1_array'];
    $int2_array = $vwireVariables['none_ae_solution_value']['int2_array'];

    $ae_array = $vwireVariables['none_ae_solution_value']['ae_array'];

    $ae_interface_prefix = "ethernet";
    $tmp_int_type = "virtual-wire";
}


$vw_interface_prefix = "vw";

///////////////////////////////////////////////////////
/// ///////////////////////////////////////////////////////

$cleanupArray = array();
$cleanupArray['securityrules'] = array();
$cleanupArray['virtualwires'] = array();
$cleanupArray['zones'] = array();
$cleanupArray['subinterfaces'] = array();
$cleanupArray['interfaces'] = array();
$cleanupArray['aes'] = array();


$supportedArguments = Array();
$supportedArguments['in'] = Array('niceName' => 'in', 'shortHelp' => 'input file or api. ie: in=config.xml  or in=api://192.168.1.1 or in=api://0018CAEC3@panorama.company.com', 'argDesc' => '[filename]|[api://IP]|[api://serial@IP]');
$supportedArguments['out'] = Array('niceName' => 'out', 'shortHelp' => 'output file to save config after changes. Only required when input is a file. ie: out=save-config.xml', 'argDesc' => '[filename]');
$supportedArguments['location'] = Array('niceName' => 'location', 'shortHelp' => 'specify if you want to limit your query to a VSYS. By default location=vsys1 for PANOS. ie: location=any or location=vsys2,vsys1', 'argDesc' => '=sub1[,sub2]');
$supportedArguments['debugapi'] = Array('niceName' => 'DebugAPI', 'shortHelp' => 'prints API calls when they happen');
$supportedArguments['help'] = Array('niceName' => 'help', 'shortHelp' => 'this message');
$supportedArguments['loadpanoramapushedconfig'] = Array('niceName' => 'loadPanoramaPushedConfig', 'shortHelp' => 'load Panorama pushed config from the firewall to take in account panorama objects and rules' );
$supportedArguments['folder'] = Array('niceName' => 'folder', 'shortHelp' => 'specify the folder where the offline files should be saved');

$usageMsg = PH::boldText("USAGE: ")."php ".basename(__FILE__)." in=inputfile.xml location=vsys1 \n".
    "php ".basename(__FILE__)." help          : more help messages\n";

##############

$util = new UTIL( "custom", $argv, $argc, __FILE__, $supportedArguments, $usageMsg );
$util->utilInit();

##########################################
##########################################

$util->load_config();
#$util->location_filter();

$pan = $util->pan;

$connector = $pan->connector;

$sub = $pan->findVirtualSystem( $util->objectsLocation);

$candidateConfig = $util->pan->xmldoc;
///////////////////////////////////////////////////////
///////////////////////////////////////////////////////
//CUSTOM variable validation

//working solution for firewall API, also if different vsys
//Todo: more validation needed for offline files if interface, virtual-wire aso already exist [pan-c framework related]

//Todo: extend for Panorama DG (location)[security rule part] and template[interfaces, virtual-wire, zone]


if( $util->configType == 'panorama' )
    derr( 'Panorama configuration extension for virtual-wire is NOT yet supported' );

if( $ae_solution && $pan->connector->info_model == "PA-VM" )
    derr( 'PA-VM do not support aggregate-group interface' );


///////////////////////////////////////////////////////
///////////////////////////////////////////////////////
$util->useException();

//trigger exception in a "try" block
// missing stuff, hold candiate config in variable
try
{

    foreach( $ae_array as $key => $i )
    {
        if( $tmp_int_type == "aggregate-group" )
        {
            $name2 = $ae_interface_prefix.$i;
            $tmp_int_type2 = "virtual-wire";

            print "create Ethernet Aggregate ".$name2." first.\n";


            if( $util->configInput['type'] == 'api' )
            {
                $tmp_VirtualWireIf2 = $pan->network->aggregateEthernetIfStore->API_newEthernetIf($name2, $tmp_int_type2);
                if( !$sub->importedInterfaces->hasInterfaceNamed( $tmp_VirtualWireIf2->name() ) )
                    $sub->importedInterfaces->API_addInterface( $tmp_VirtualWireIf2 );
            }
            else
            {
                $tmp_VirtualWireIf2 = $pan->network->aggregateEthernetIfStore->newEthernetIf($name2, $tmp_int_type2);
                if( !$sub->importedInterfaces->hasInterfaceNamed( $tmp_VirtualWireIf2->name() ) )
                    $sub->importedInterfaces->addInterface( $tmp_VirtualWireIf2 );
            }
            $cleanupArray['aes'][] = $tmp_VirtualWireIf2;

            print "import ".$tmp_VirtualWireIf2->type()." Interface ".$tmp_VirtualWireIf2->name()." to vsys ".$sub->name()."\n";

            print "zone: ".$vw_interface_prefix."_".$name2." as type: ".$tmp_VirtualWireIf2->type()." created\n";
            $zone = $sub->zoneStore->newZone( $vw_interface_prefix."_".$name2, $tmp_VirtualWireIf2->type() );
            $zone->attachedInterfaces->addInterface( $tmp_VirtualWireIf2 );

            if( $util->configInput['type'] == 'api' )
            {
                print "API: zoneStore sync\n";
                $zone->API_sync();
            }
            $cleanupArray['zones'][] = $zone;



            if ( $key == 1)
                $int_array = $int2_array;
            else
                $int_array = $int1_array;

            foreach( $int_array as $ii )
            {
                $name = "ethernet" . $ii;

                print "create ".$tmp_int_type." | " . $name . " Interface with AE: " . $name2 . "\n";
                if( $util->configInput['type'] == 'api' )
                {
                    $tmp_VirtualWireIf = $pan->network->ethernetIfStore->API_newEthernetIf($name, $tmp_int_type, $name2);
                    if( !$sub->importedInterfaces->hasInterfaceNamed( $tmp_VirtualWireIf->name() ) )
                        $sub->importedInterfaces->API_addInterface( $tmp_VirtualWireIf );
                }

                else
                {
                    $tmp_VirtualWireIf = $pan->network->ethernetIfStore->newEthernetIf($name, $tmp_int_type, $name2);

                    if( !$sub->importedInterfaces->hasInterfaceNamed( $tmp_VirtualWireIf->name() ) )
                        $sub->importedInterfaces->addInterface( $tmp_VirtualWireIf );
                }
                $cleanupArray['interfaces'][] = $tmp_VirtualWireIf;

                //NO vsys import needed (possible) for aggreagte-group ethernet interfaces
            }
        }
        else
        {
            //$name missing how to proceed?

            if ( $key == 1)
                $int_array = $int2_array;
            else
                $int_array = $int1_array;

            $interface_array = array();

            foreach( $int_array as $ii )
            {
                $name = $ae_interface_prefix . $ii;

                print "create ".$tmp_int_type." ".$name." Interface\n";


                if( $util->configInput['type'] == 'api' )
                {
                    $tmp_VirtualWireIf = $pan->network->ethernetIfStore->API_newEthernetIf($name, $tmp_int_type);
                    if( !$sub->importedInterfaces->hasInterfaceNamed( $tmp_VirtualWireIf->name() ) )
                        $sub->importedInterfaces->API_addInterface( $tmp_VirtualWireIf );
                }
                else
                {
                    $tmp_VirtualWireIf = $pan->network->ethernetIfStore->newEthernetIf($name, $tmp_int_type);
                    if( !$sub->importedInterfaces->hasInterfaceNamed( $tmp_VirtualWireIf->name() ) )
                        $sub->importedInterfaces->addInterface( $tmp_VirtualWireIf );
                }
                $cleanupArray['interfaces'][] = $tmp_VirtualWireIf;

                print "import ".$tmp_VirtualWireIf->type()." ".$name." Interface ".$tmp_VirtualWireIf->name()." to vsys ".$sub->name()."\n";

                $interface_array[] = $tmp_VirtualWireIf;

                print "create zone: ".$vw_interface_prefix."_".$name." as type: ".$tmp_VirtualWireIf->type()."\n";
                $tmp_int_name = str_replace( "/", "_", $name );
                $zone = $sub->zoneStore->newZone( $vw_interface_prefix."_".$tmp_int_name, $tmp_VirtualWireIf->type() );
                $zone->attachedInterfaces->addInterface( $tmp_VirtualWireIf );

                if( $util->configInput['type'] == 'api' )
                {
                    print "API: zoneStore sync\n";
                    $zone->API_sync();
                }
                $cleanupArray['zones'][] = $zone;
            }
        }



        foreach( $vlan_array as $vlan )
        {
            if( $tmp_VirtualWireIf->type() !== "aggregate-group" )
            {
                //this is for example for virtual-wire subinterfaces

                foreach( $interface_array as $tmp_VirtualWireIf )
                {
                    print "create Subinterface ".$tmp_VirtualWireIf->type()." interface: ".$tmp_VirtualWireIf->name().".".$vlan." - added to vsys: ".$sub->name()."\n";
                    $tmp_int_name = str_replace( "/", "_", $tmp_VirtualWireIf->name() );
                    $zone_name = $vw_interface_prefix."_".$tmp_int_name."-".$vlan;


                    if( $util->configInput['type'] == 'api' )
                    {
                        $newInt = $tmp_VirtualWireIf->API_addSubInterface( $vlan);
                        if( !$sub->importedInterfaces->hasInterfaceNamed( $newInt->name() ) )
                            $sub->importedInterfaces->API_addInterface( $newInt );
                    }
                    else
                    {
                        $newInt = $tmp_VirtualWireIf->addSubInterface( $vlan);
                        if( !$sub->importedInterfaces->hasInterfaceNamed( $newInt->name() ) )
                            $sub->importedInterfaces->addInterface( $newInt );
                    }
                    $cleanupArray['subinterfaces'][] = $newInt;

                    print "create zone: ".$zone_name." as type: ".$tmp_VirtualWireIf->type()."\n";
                    $zone = $sub->zoneStore->newZone( $zone_name, $tmp_VirtualWireIf->type() );
                    $zone->attachedInterfaces->addInterface( $newInt );

                    if( $util->configInput['type'] == 'api' )
                    {
                        print "API: zone sync\n";
                        $zone->API_sync();
                    }
                    $cleanupArray['zones'][] = $zone;
                }

            }
            elseif( $tmp_VirtualWireIf2->type() !== "aggregate-ethernet" )
            {
                print "create Subinterface ".$tmp_VirtualWireIf2->type()." interface: ".$tmp_VirtualWireIf2->name().".".$vlan." - added to vsys: ".$sub->name()."\n";
                $zone_name = $vw_interface_prefix."_".$tmp_VirtualWireIf2->name()."-".$vlan;
                print "create zone: ".$zone_name." as type: ".$tmp_VirtualWireIf2->type()."\n";

                if( $util->configInput['type'] == 'api' )
                {
                    $newInt = $tmp_VirtualWireIf2->API_addSubInterface( $vlan);
                    if( !$sub->importedInterfaces->hasInterfaceNamed( $newInt->name() ) )
                        $sub->importedInterfaces->API_addInterface( $newInt );
                }
                else
                {
                    $newInt = $tmp_VirtualWireIf2->addSubInterface( $vlan);
                    if( !$sub->importedInterfaces->hasInterfaceNamed( $newInt->name() ) )
                        $sub->importedInterfaces->addInterface( $newInt );
                }
                $cleanupArray['subinterfaces'] = array();

                $zone = $sub->zoneStore->newZone( $zone_name, $tmp_VirtualWireIf2->type() );
                $zone->attachedInterfaces->addInterface( $newInt );

                if( $util->configInput['type'] == 'api' )
                {
                    print "API: zoneStore sync\n";
                    $zone->API_sync();
                }
                $cleanupArray['zones'] = array();
            }
        }
    }


    if( $tmp_int_type == "aggregate-group" )
    {
        $name = $ae_interface_prefix."_".$ae_array[0]."-".$ae_array[1];

        print "search for: ".$ae_interface_prefix . $ae_array[0] . "\n";
        print "search for: ".$ae_interface_prefix . $ae_array[1] . "\n";
        $int_ae1 = $pan->network->aggregateEthernetIfStore->findOrCreate($ae_interface_prefix . $ae_array[0]);
        $int_ae2 = $pan->network->aggregateEthernetIfStore->findOrCreate($ae_interface_prefix . $ae_array[1]);
    }
    elseif( $tmp_int_type == "virtual-wire" )
    {
        $tmp_int1_name = str_replace( "/", "_", $int1_array[0] );
        $tmp_int2_name = str_replace( "/", "_", $int1_array[1] );
        $name = $ae_interface_prefix."_".$tmp_int1_name."-".$tmp_int2_name;

        print "search for: ".$ae_interface_prefix . $int1_array[0] . "\n";
        print "search for: ".$ae_interface_prefix . $int1_array[1] . "\n";
        $int_ae1 = $pan->network->aggregateEthernetIfStore->findOrCreate($ae_interface_prefix . $int1_array[0]);
        $int_ae2 = $pan->network->aggregateEthernetIfStore->findOrCreate($ae_interface_prefix . $int1_array[1]);
    }
    $name = str_replace( "ethernet", "eth", $name );


    print "create Virtual Wire: ".$name." Interface\n";

    if( $util->configInput['type'] == 'api' )
    {
        $tmp_VirtualWireIf = $pan->network->virtualWireStore->API_newVirtualWire( $name );
        if( count($vlan_array) === 0 )
        {
            #Todo: 20221017 swaschkut - missing method to API_setTagAllowed
            PH::print_stdout();
            PH::print_stdout("##############################################################");
            PH::print_stdout( "please set vwire: ".$name." Tag Allowed manual to: '0-4094'" );
            PH::print_stdout("##############################################################");
            PH::print_stdout();
        }
    }
    else
    {
        $tmp_VirtualWireIf = $pan->network->virtualWireStore->newVirtualWire( $name );
        if( count($vlan_array) === 0 )
        {
            #Todo: 20221017 swaschkut - missing method to setTagAllowed
            PH::print_stdout();
            PH::print_stdout("##############################################################");
            PH::print_stdout( "please set vwire: ".$name." Tag Allowed manual to: '0-4094'" );
            PH::print_stdout("##############################################################");
            PH::print_stdout();
        }
    }
    $cleanupArray['virtualwires'][] = $tmp_VirtualWireIf;


    print "add interfaces: " . $int_ae1->name() . " and " . $int_ae2->name() . " to Virtual Wire: " . $name . " Interface\n";
    if( $util->configInput['type'] == 'api' )
    {
        $tmp_VirtualWireIf->API_setInterface('interface1', $int_ae1);
        $tmp_VirtualWireIf->API_setInterface('interface2', $int_ae2);
    }
    else
    {
        $tmp_VirtualWireIf->setInterface('interface1', $int_ae1);
        $tmp_VirtualWireIf->setInterface('interface2', $int_ae2);
    }





    print "search interfacename: ".$int_ae1->name()."\n";
    print "search interfacename: ".$int_ae2->name()."\n";
    $zone_ae1 = $sub->zoneStore->findZoneMatchingInterfaceName( $int_ae1->name() );
    $zone_ae2 = $sub->zoneStore->findZoneMatchingInterfaceName( $int_ae2->name() );

    if( $tmp_int_type == "aggregate-group" )
    {
        $tmp_rule1_name = $int_ae1->name()."-".$int_ae2->name();
        $tmp_rule2_name = $int_ae2->name()."-".$int_ae1->name();
    }
    elseif( $tmp_int_type == "virtual-wire" )
    {
        $tmp_int1_name = str_replace( "/", "_", $int_ae1->name() );
        $tmp_int2_name = str_replace( "/", "_", $int_ae2->name() );
        $tmp_rule1_name = $tmp_int1_name."-".$tmp_int2_name;
        $tmp_rule2_name = $tmp_int2_name."-".$tmp_int1_name;
    }

    $tmp_rule1_name = str_replace( "ethernet", "eth", $tmp_rule1_name );
    $tmp_rule2_name = str_replace( "ethernet", "eth", $tmp_rule2_name );

    print "create rule: ".$tmp_rule1_name." and add zones from/to\n";
    $sec_rule1 = $sub->securityRules->newSecurityRule( $tmp_rule1_name );
    $cleanupArray['securityrules'][] = $sec_rule1;

    $sec_rule1->from->addZone( $zone_ae1 );
    $sec_rule1->to->addZone( $zone_ae2 );

    print "create rule: ".$tmp_rule2_name." and add zones from/to\n";
    $sec_rule2 = $sub->securityRules->newSecurityRule( $tmp_rule2_name );
    $cleanupArray['securityrules'][] = $sec_rule2;

    $sec_rule2->from->addZone( $zone_ae2 );
    $sec_rule2->to->addZone( $zone_ae1 );

    if( $util->configInput['type'] == 'api' )
    {
        print "API: rule1 / rule2 sync\n";
        $sec_rule1->API_sync();
        $sec_rule2->API_sync();
    }



    foreach( $vlan_array as $vlan )
    {
        if( $tmp_int_type == "aggregate-group" )
            $vw_name = $ae_interface_prefix."_".$ae_array[0]."-".$ae_array[1]."_".$vlan;
        elseif( $tmp_int_type == "virtual-wire" )
        {
            $tmp_int1_name = str_replace( "/", "_", $int1_array[0] );
            $tmp_int2_name = str_replace( "/", "_", $int1_array[1] );
            $vw_name = $ae_interface_prefix."_".$tmp_int1_name."-".$vlan."_".$tmp_int2_name."-".$vlan;
        }
        $vw_name = str_replace( "ethernet", "eth", $vw_name );

        print "create Virtual Wire: ".$name." Interface\n";

        if( $util->configInput['type'] == 'api' )
        {
            $tmp_VirtualWireSubIf = $pan->network->virtualWireStore->API_newVirtualWire( $vw_name );
            //Todo: nothing for sub-interfaces you ar enot allowed to set anything
        }
        else
        {
            $tmp_VirtualWireSubIf = $pan->network->virtualWireStore->newVirtualWire( $vw_name );
            //Todo: nothing for sub-interfaces you ar enot allowed to set anything
        }
        $cleanupArray['virtualwires'][] = $tmp_VirtualWireSubIf;


        foreach( $ae_array as $key => $i )
        {
            if( $tmp_int_type == "aggregate-group" )
            {
                $tmp_int1_name = $ae_interface_prefix . $ae_array[0] . "." . $vlan;
                $tmp_int2_name = $ae_interface_prefix . $ae_array[1] . "." . $vlan;
            }
            else
            {
                $tmp_int1_name = $ae_interface_prefix . $int1_array[0] . "." . $vlan;
                $tmp_int2_name = $ae_interface_prefix . $int1_array[1] . "." . $vlan;
            }
            print "search for: ".$tmp_int1_name."\n";
            print "search for: ".$tmp_int2_name."\n";
            $int_subae1 = $pan->network->ethernetIfStore->findOrCreate($tmp_int1_name);
            $int_subae2 = $pan->network->ethernetIfStore->findOrCreate($tmp_int2_name);
            $cleanupArray['subinterfaces'][] = $int_subae1;
            $cleanupArray['subinterfaces'][] = $int_subae2;

            print "add interfaces: " . $int_subae1->name() . " and " . $int_subae2->name() . " to Virtual Wire: " . $vw_name . " Interface\n";
            if( $util->configInput['type'] == 'api' )
            {
                $tmp_VirtualWireSubIf->API_setInterface('interface1', $int_subae1);
                $tmp_VirtualWireSubIf->API_setInterface('interface2', $int_subae2);
            }
            else
            {
                $tmp_VirtualWireSubIf->setInterface('interface1', $int_subae1);
                $tmp_VirtualWireSubIf->setInterface('interface2', $int_subae2);
            }




            $zone_subae1 = $sub->zoneStore->findZoneMatchingInterfaceName( $int_subae1->name() );
            $zone_subae2 = $sub->zoneStore->findZoneMatchingInterfaceName( $int_subae2->name() );

            if( $tmp_int_type == "aggregate-group" )
            {
                $tmp_rule1_name = $int_subae1->name()."-".$int_subae2->name();
                $tmp_rule2_name = $int_subae2->name()."-".$int_subae1->name();
            }
            elseif( $tmp_int_type == "virtual-wire" )
            {
                $tmp_int1_name = str_replace( "/", "_", $int_subae1->name() );
                $tmp_int2_name = str_replace( "/", "_", $int_subae2->name() );
                $tmp_rule1_name = $tmp_int1_name."-".$tmp_int2_name;
                $tmp_rule2_name = $tmp_int2_name."-".$tmp_int1_name;
            }

            $tmp_rule1_name = str_replace( "ethernet", "eth", $tmp_rule1_name );
            $tmp_rule2_name = str_replace( "ethernet", "eth", $tmp_rule2_name );

            print "create rule: ".$tmp_rule1_name." and add zones from/to\n";
            $sec_rule1 = $sub->securityRules->newSecurityRule( $tmp_rule1_name );
            $cleanupArray['securityrules'][] = $sec_rule1;

            $sec_rule1->from->addZone( $zone_subae1 );
            $sec_rule1->to->addZone( $zone_subae2 );

            print "create rule: ".$tmp_rule2_name." and add zones from/to\n";
            $sec_rule2 = $sub->securityRules->newSecurityRule( $tmp_rule2_name );
            $cleanupArray['securityrules'][] = $sec_rule2;

            $sec_rule2->from->addZone( $zone_subae2 );
            $sec_rule2->to->addZone( $zone_subae1 );

            if( $util->configInput['type'] == 'api' )
            {
                print "API: rule1 / rule2 sync\n";
                $sec_rule1->API_sync();
                $sec_rule2->API_sync();
            }
        }
    }


}
//catch exception
catch(Exception $e)
{
    //Todo: 2019016 on failure change back config;
    //uption: get candidate config first, if error, upload candidate config again

    print "\n";
    print "Error Message: " .$e->getMessage()."\n";

    if( $util->configInput['type'] == 'api' )
    {
        print "\noverview about creation: - now need to cleanup created stuff as PAN-OS API was used\n";

        print " - securityrules: ".count($cleanupArray['securityrules'])."\n";
        foreach( $cleanupArray['securityrules'] as $object )
        {
            /** @var SecurityRule $object */
            $objectName = $object->name();

            $object->owner->API_remove($object);
            print "   - " . $objectName . " removed\n";
        }


        print " - virtualwires:".count($cleanupArray['virtualwires'])."\n";
        foreach( $cleanupArray['virtualwires'] as $virtualwire )
        {
            $objectName = $virtualwire->name();
            /** @var VirtualWire $virtualwire */
            print "   - " . $objectName . "\n";
            mwarning("virtualwire remove not implemented", null, FALSE);
            #print "   - " . $objectName . " removed\n";
        }


        print " - zones:".count($cleanupArray['zones'])."\n";
        foreach( $cleanupArray['zones'] as $zone )
        {
            $objectName = $zone->name();
            $owner = $zone->owner;

            /** @var Zone $zone */
            $zone->owner->removeZone($zone);
            $owner->API_sync();

            print "   - " . $objectName . " removed\n";
        }


        print " - interfaces:".count($cleanupArray['interfaces'])."\n";
        foreach( $cleanupArray['interfaces'] as $interface )
        {
            $objectName = $interface->name();
            /** @var EthernetInterface $interface */
            print "   - " . $objectName . "\n";
            mwarning("interface remove not implemented", null, FALSE);
        }

        print " - aes:".count($cleanupArray['aes'])."\n";
        foreach( $cleanupArray['aes'] as $object )
        {
            $objectName = $object->name();

            print "   - " . $objectName . "\n";
            mwarning("interface remove not implemented", null, FALSE);
        }
    }
}
$util->disableExceptionSupport();
##############################################

print "\n\n\n";

// save our work !!!
$util->save_our_work();



print "\n\n************ END OF CREATE-INTERFACE UTILITY ************\n";
print     "**************************************************\n";
print "\n\n";
