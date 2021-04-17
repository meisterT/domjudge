package main

import (
    "bytes"
    "encoding/json"
    "fmt"
    "io"
    "io/ioutil"
    "log"
    "net/http"
    "net/url"
    "os"
    "os/exec"
    "reflect"
    "strings"
    "time"
)

// TODO: generate these
const RUNUSER = "domjudge-run"
const ETCDIR  = "/home/sitowert/domjudge/etc"
const LIBJUDGEDIR = "/home/sitowert/domjudge/lib/judge"
const JUDGEDIR = "/home/sitowert/domjudge/output/judgings"

const CHROOT_SCRIPT = "chroot-startstop.sh"

type Endpoint struct {
    id string
    url string
    user string
    password string
    waiting bool
    errored bool
    lastAttempt int64
}

var (
    endpoints map[string]Endpoint
    endpointIds []string
    infoLogger *log.Logger
    domjudgeConfig map[string]interface{}
)

func readCredentials() error {
    endpoints = make(map[string]Endpoint)

    credentialsFile := ETCDIR + "/restapi.secret"
    data, err := ioutil.ReadFile(credentialsFile)
    if err != nil {
        fmt.Println("File reading error", err)
        return err
    }

    lines := strings.Split(string(data[:]), "\n")
    for lineno, line := range lines {
        if len(line) < 1 || line[0] == '#' {
            continue;
        }
        tokens := strings.Fields(line);
        if len(tokens) != 4 {
            panic(
                fmt.Sprintf("Error parsing REST API credentials. Invalid format in line %d, got %d tokens, expected 4.",
                    lineno+1, len(tokens)))
        }
        endpointId := tokens[0]
        endpointIds = append(endpointIds, endpointId)
        if _, exists := endpoints[endpointId]; exists {
            panic(
                fmt.Sprintf("Error parsing REST API credentials. Duplicate Endpoint ID '%s' in line %d.",
                    endpointId, lineno+1))
        }
        endpoints[endpointId] = Endpoint{
            endpointId,
            tokens[1],
            tokens[2],
            tokens[3],
            false,
            false,
            -1,
        }
    }

    if len(endpoints) == 0 {
        panic("Error parsing REST API credentials: no endpoints found.");
    }

    return nil
}

func basicChrootCheck() {
    // Check basic prerequisites for chroot at judgehost startup.
    infoLogger.Print("√ Executing chroot script: '", CHROOT_SCRIPT, " check'")
    var out bytes.Buffer
    cmd := exec.Command(fmt.Sprint(LIBJUDGEDIR, "/", CHROOT_SCRIPT), "check")
    cmd.Stdout = &out
    err := cmd.Run()
    if err != nil {
        infoLogger.Print("chroot sanity check failed:");
        panic(err)
    }
}

func request(endpoint Endpoint, target string, verb string, data map[string]string) []byte {
    // TODO: Don't flood the log
    infoLogger.Print("API request ", verb, " / ", target)

    var body = io.Reader(nil)
    // TODO: Handle GET correctly
    if data != nil {
        requestData := url.Values{}
        for k, v := range(data) {
            requestData.Set(k, v);
        }
        infoLogger.Print("data: ", string(requestData.Encode()))
        body = strings.NewReader(requestData.Encode())
    }

    fullUrl := fmt.Sprint(endpoint.url, "/", target)
    request, err := http.NewRequest(verb, fullUrl, body)
    request.SetBasicAuth(endpoint.user, endpoint.password)
    request.Header.Set("User-Agent", "DOMjudge/vTODO")
    if verb == "POST" {
        request.Header.Add("Content-Type", "application/x-www-form-urlencoded")
    }

    client := &http.Client{}
    response, err := client.Do(request)
    if err != nil {
        infoLogger.Print("API request failed")
        panic(err)
    }
    defer response.Body.Close()

    infoLogger.Print("Status code: ", string(response.Status))

    responseBody, err := ioutil.ReadAll(response.Body)
    if err != nil {
        panic(err)
    }

    if response.StatusCode < 200 || response.StatusCode >= 300 {
        var errstr = fmt.Sprint("API request returned HTTP status code ", response.StatusCode, ": ")
        if response.StatusCode == 401 {
            errstr = fmt.Sprint(errstr, "Authentication failed while contacting ", fullUrl, ". ",
                "Check credentials in 'etc/restapi.secret'.")
        } else {
            var jsonData interface{}
            err := json.Unmarshal(responseBody, &jsonData)
            if err != nil {
                infoLogger.Print("Failed to decode JSON.")
            } else {
                errstr = fmt.Sprintf("%s%v", errstr, jsonData)
            }
        }
        endpoint.errored = true
        infoLogger.Print(errstr)
        // TODO: maybe fail on error here, and return an err?
        return nil
    }

    if endpoint.errored {
        endpoint.errored = false
        endpoint.waiting = false
        infoLogger.Print("Reconnected to endpoint ", endpoint.id)
    }

    return responseBody
}

func registerJudgehost(endpoint Endpoint, myhost string) {
    // Only try to register every 30s.
    now := time.Now().Unix()
    if now - endpoint.lastAttempt < 30 {
        endpoint.waiting = true
        return;
    }
    endpoint.lastAttempt = now

    infoLogger.Print("Registering judgehost on endpoint '", endpoint.id, "' with url: ", endpoint.url)
    // TODO: set up curl handle?

    // Create directory where to test submissions
    workdirpath := fmt.Sprint(JUDGEDIR, "/", myhost, "/", "endpoint-", endpoint.id)
    testcasepath := fmt.Sprint(workdirpath, "/testcase")
    if err := os.MkdirAll(testcasepath, 0700); err != nil {
        infoLogger.Print("Could not create '", testcasepath, "'")
        panic(err)
    }

    // Auto-register judgehost.
    // If there are any unfinished judgings in the queue in my name, they will not be finished. Give them back.
    response := request(endpoint, "judgehosts", "POST", map[string]string{
        "hostname": myhost,
    })

    if response == nil {
        infoLogger.Print("Registering judgehost on endpoint ", endpoint.id, " failed.")
    } else {
        var jsonData []map[string]interface{}
        err := json.Unmarshal(response, &jsonData)
        if err != nil {
            infoLogger.Print("Failed to decode JSON.")
        } else {
            for _, unfinished := range (jsonData) {
                // TODO: change permissions of old judging dir
                infoLogger.Print("Found unfinished judging with jobid ", unfinished["jobid"], " in my name. ",
                    "Given back unfinished runs from me.")
            }
        }
    }
}

// Retrieve the configuration through the REST API.
func djconfig_refresh(endpoint Endpoint) {
    response := request(endpoint, "config", "GET", nil);
    err := json.Unmarshal(response, &domjudgeConfig)
    if err != nil {
        panic(err)
    }
}

// Retrieve a value from the configuration - which has to be initialized before.
func djconfig_get_value(name string) interface{} {
    if len(domjudgeConfig) == 0 {
        panic("DOMjudge config not initialised before call to djconfig_get_value().")
    }
    return domjudgeConfig[name]
}

// 2021/04/01 21:01:32  json [
// map[
// hostname:<nil> type:judging_run priority:0 run_script_id:3 compare_script_id:1
// compile_config:{"script_timelimit":30,"script_memory_limit":2097152,"script_filesize_limit":2621440,"language_extensions":["cpp","cc","cxx","c++"],"filter_compiler_files":true,"hash":"ec4d1dae1c008d09577a3618814fe53b"} judgetaskid:21 jobid:21 submitid:18 compile_script_id:8 testcase_id:1 run_config:{"time_limit":5,"memory_limit":2097152,"output_limit":8192,"process_limit":64,"entry_point":null,"hash":"8f6a40aab30f3a816e80bb1b76fc0d6c"} compare_config:{"script_timelimit":30,"script_memory_limit":2097152,"script_filesize_limit":2621440,"compare_args":null,"combined_run_compare":false,"hash":"80822e09ea714b3238620203f466caff"}]]

type JudgeTask struct {
    Id int64 `json:"judgetaskid"`
    JobId string `json:"jobid"`
    SubmitId string `json:"submitid"`
    Kind string `json:"type"`
    CompileConfig interface{} `json:"compile_config"`
    RunConfig interface{} `json:"run_config"`
    CompareConfig interface{} `json:"compare_config"`
    CompileScriptId string `json:"compile_script_id"`
    RunScriptId string `json:"run_script_id"`
    CompareScriptId string `json:"compare_script_id"`
}

func loop(myhost string) {
    //var lastWorkdir = string(nil)

    // Pick first endpoint.
    var currentEndpointIdx = 0

    for {
        // If all endpoints are waiting, sleep for a bit.
        var dosleep = true
        for _, endpoint := range endpoints {
            if endpoint.errored {
                registerJudgehost(endpoint, myhost)
            }
            if !endpoint.waiting {
                dosleep = false
                break
            }
        }

        // Sleep only if everything is "waiting" and only if we're looking at the first endpoint again.
        if dosleep && currentEndpointIdx == 0 {
            time.Sleep(5 * time.Second)
        }

        // Increment our currentEndpoint pointer.
        currentEndpointIdx = (currentEndpointIdx + 1) % len(endpointIds);
        currentEndpoint := endpoints[endpointIds[currentEndpointIdx]]
        workdirpath := fmt.Sprint(JUDGEDIR, "/", myhost, "/endpoint-", currentEndpoint.id)
        infoLogger.Printf("wdp = %v", workdirpath)

        // TODO: Handle signals

        if currentEndpoint.errored {
            continue
        }

        if !currentEndpoint.waiting {
            // TODO: Check for low disk space
        }

        // Request open submissions to judge. Any errors will be treated as
        // non-fatal: we will just keep on retrying in this loop.
        judging := request(currentEndpoint, "judgehosts/fetch-work", "POST", map[string]string{
            "hostname": myhost,
        })

        if judging != nil {
            infoLogger.Printf(" raw %v", judging)
            infoLogger.Printf(" raw %v", string(judging))
            var jsonData []interface{}
            json.Unmarshal(judging, &jsonData)
            infoLogger.Printf(" json %v", jsonData)

            var judgetask []JudgeTask
            err := json.Unmarshal(judging, &judgetask)
            if err != nil {
                panic(err)
            }
            // If $judging is null, an error occurred; don't try to decode.
            infoLogger.Printf(" received %v", judgetask)
            infoLogger.Printf(" cc %v", reflect.TypeOf(judgetask[0].CompileConfig))
            infoLogger.Printf(" cc %v", judgetask[0].CompileConfig)
        }
        os.Exit(0)
    }
}

func main() {
    infoLogger = log.New(os.Stdout, "", log.Ldate|log.Ltime)
    myhost, err := os.Hostname()
    if err != nil {
        panic(err)
    }
    infoLogger.Print("Hostname: ", myhost)

    // TODO: take from command line
    daemon_id := 0
    runuser := RUNUSER + string(daemon_id)
    infoLogger.Print(runuser)

    // TODO: put exitcodes into env

    // TODO: syslog relevant?

    // TODO: signal handling?

    readCredentials()

    // TODO: umask necessary?

    basicChrootCheck()

    // TODO: potentially daemonize?

    for _, endpoint := range endpoints {
        registerJudgehost(endpoint, myhost)
        // Populate the DOMjudge configuration initially.
        djconfig_refresh(endpoint)
    }

    loop(myhost)
}
