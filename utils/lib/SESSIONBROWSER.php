<?php

/**
 * ISC License
 *
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

class SESSIONBROWSER extends UTIL
{
    public $utilType = null;


    public function utilStart()
    {

        $this->supportedArguments = Array();
        $this->supportedArguments['in'] = Array('niceName' => 'in', 'shortHelp' => 'input file ie: in=config.xml', 'argDesc' => '[filename]');
        $this->supportedArguments['location'] = Array('niceName' => 'Location', 'shortHelp' => 'specify if you want to limit your query to a VSYS/DG. By default location=shared for Panorama, =vsys1 for PANOS', 'argDesc' => 'vsys1|shared|dg1');
        $this->supportedArguments['actions'] = Array('niceName' => 'Actions', 'shortHelp' => 'action to apply on each rule matched by Filter. ie: actions=from-Add:net-Inside,netDMZ', 'argDesc' => 'action:arg1[,arg2]' );
        $this->supportedArguments['debugapi'] = Array('niceName' => 'DebugAPI', 'shortHelp' => 'prints API calls when they happen');
        $this->supportedArguments['filter'] = Array('niceName' => 'Filter', 'shortHelp' => "filters logs based on a query. ie: 'filter=( (subtype eq auth) and ( receive_time geq !TIME! ) )'", 'argDesc' => '(field operator value)');
        $this->supportedArguments['help'] = Array('niceName' => 'help', 'shortHelp' => 'this message');
        $this->supportedArguments['stats'] = Array('niceName' => 'Stats', 'shortHelp' => 'display stats after changes');
        $this->supportedArguments['hours'] = Array('niceName' => 'Hours', 'shortHelp' => 'display log for the last few hours');
        $this->supportedArguments['apitimeout'] = Array('niceName' => 'apiTimeout', 'shortHelp' => 'in case API takes too long time to anwer, increase this value (default=60)');

        $this->usageMsg = PH::boldText('USAGE: ')."php ".basename(__FILE__)." in=api://192.168.55.100 location=shared [Actions=display] ['Filter=(subtype eq pppoe)'] ...";


        $this->prepareSupportedArgumentsArray();


        $this->utilInit();


        $this->main();


        
    }

    public function main()
    {

        #$util = new UTIL( "custom", $argv, $argc, __FILE__, $supportedArguments, $usageMsg );
        #$util->utilInit();
#$util->load_config();

        #if( !$this->pan->isFirewall() )
        #    derr( "only PAN-OS FW is supported" );

#if( !$util->apiMode && !$offline_config_test )
        if( !$this->apiMode )
            derr( "only PAN-OS API connection is supported" );

        $inputConnector = $this->pan->connector;

########################################################################################################################

        if( isset(PH::$args['hours']) )
            $hours = PH::$args['hours'];
        else
            $hours = 0.25;
        PH::print_stdout( " - argument 'hours' set to '{$hours}'" );

        $this->setTimezone();

        $time = time() - ($hours * 3600);
        $time = date('Y/m/d H:i:s', $time);


        if( isset(PH::$args['filter']) )
        {
            $filterquery = "<filter>".PH::$args['filter']."</filter>";
            $filterquery = str_replace( "!TIME!", "'".$time."'", $filterquery );
            //Todo: session filter is working differently compare to session filter in UI
            #$filterquery = '';
        }
        else
        {
            $filterquery = '';
        }

########################################################################################################################

        $inputConnector->refreshSystemInfos();
        $inputConnector->setShowApiCalls( $this->debugAPI );

        /*
        $apiArgs = Array();
        $apiArgs['type'] = 'log';
        $apiArgs['log-type'] = 'traffic';
        $apiArgs['query'] = $query;


        $output = $inputConnector->getLog($apiArgs);
        */
        #$query = '<show><session><all><filter><from>DMZ</from><to>untrust</to></filter></all></session></show>';
        $query = '<show><session><all>'.$filterquery.'</all></session></show>';
        $apiArgs = Array();
        $apiArgs['type'] = 'op';
        $apiArgs['cmd'] = $query;

        $output = $inputConnector->getSession($apiArgs);


        PH::print_stdout();
        PH::print_stdout( "##########################################" );
        PH::print_stdout( "session browser filter: '".$filterquery."'" );
        PH::print_stdout();

        if( !empty($output) )
        {
            foreach( $output as $log )
            {
                PH::print_stdout(  " - ".http_build_query($log,'',' | ') );
                PH::print_stdout();

                PH::$JSON_OUT['session-browser'][] = $log;
            }
        }
        else
        {
            PH::print_stdout( "nothing found" );
            PH::print_stdout();

            PH::$JSON_OUT['session-browser'] = array();
        }

        PH::print_stdout( "##########################################" );
        PH::print_stdout();
    }

}