<?php

namespace App\Http\Controllers;

use App\Http\Resources\VoterListBasic;
use App\Http\Resources\VoterListFull;
use App\Http\Resources\VoterBasic;
use App\Models\VoterList;
use App\Models\Voter;
use App\Services\Ballot;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VoterListApiController extends Controller
{

    public function show(VoterList $voterlist)
    {
        return new VoterListFull($voterlist);
    }

    public function showBasic(VoterList $voterlist)
    {
        return new VoterListBasic($voterlist);
    }

    public function list(Request $request)
    {
        $params = $request->all();
        $settings = [
            'page' =>
            'integer',
            'size' =>
            'integer',
            'sort_by' =>
            'string|in:id,owner,title|required_with:sort_direction',
            'sort_direction' =>
            'in:desc,asc|required_with:sort_by',
        ];

        if ($errors = $this->findErrors($params, $settings)) {
            return $errors;
        }

        $query = VoterList::with('voters', 'sentMessages');

        $query->where('owner', $this->getOwner());

        if (!empty($params['sort_by'])) {
            $query->orderBy(
                $params['sort_by'],
                $params['sort_direction']
            );
        }

        return VoterListBasic::collection(
            $query
                ->paginate($params['size'] ?? 5)
                ->appends($params)
        );
    }

    public function listVoters(Request $request, VoterList $voterlist)
    {
        $params = $request->all();
        $settings = [
            'page' =>
            'integer',
            'size' =>
            'integer',
            'sort_by' =>
            'string|in:id,email,title,phone|required_with:sort_direction',
            'sort_direction' =>
            'in:desc,asc|required_with:sort_by',
        ];

        if ($errors = $this->findErrors($params, $settings)) {
            return $errors;
        }

        $query = $voterlist->voters();

        if (!empty($params['sort_by'])) {
            $query->orderBy(
                $params['sort_by'],
                $params['sort_direction']
            );
        }

        return VoterBasic::collection(
            $query
                ->paginate($params['size'] ?? 50)
                ->appends($params)
        );
    }

    public function delete(VoterList $voterlist)
    {
        $voterlist->delete();
        return $this->basicResponse(200);
    }

    public function create(Request $request)
    {
        $params = $request->all();
        $settings = [
            'title' =>
            'required|string',
        ];

        if ($errors = $this->findErrors($params, $settings)) {
            return $errors;
        }

        $data = [
            'title' => $params['title'],
            'owner' => $this->getOwner(),
        ];

        $voterlist = VoterList::create($data);

        return new VoterListFull($voterlist);
    }

    public function update(Request $request, VoterList $voterlist)
    {
        $params = $request->all();
        $settings = [
            'title' =>
            'required|string',
        ];

        if ($errors = $this->findErrors($params, $settings)) {
            return $errors;
        }

        $voterlist->title = $params['title'];
        $voterlist->save();

        return new VoterListFull($voterlist);
    }

    public function addVoters(Request $request, VoterList $voterlist)
    {
        $params = $request->all();
        $settings = [
            'voters' =>
            'required|json',
            'voters.*.title' =>
            'required|string',
            'voters.*.email' =>
            'sometimes|email',
            'voters.*.phone' =>
            'sometimes|string',
        ];

        if ($errors = $this->findErrors($params, $settings)) {
            return $errors;
        }

        $voters = json_decode($params['voters']);
        foreach ($voters as $voterData) {
            $voter = Voter::create(
                [
                    'title' => $voterData->title,
                    'email' => $voterData->email ?? null,
                    'phone' => $voterData->phone ?? null,
                ]
            );
            $voterlist->voters()->attach($voter);
        }

        $voterlist->save();

        return new VoterListFull($voterlist);
    }

    public function removeVoters(Request $request, VoterList $voterlist)
    {
        $params = $request->all();
        $settings = [
            'voters' =>
            'required|json', // Should be just array
        ];

        if ($errors = $this->findErrors($params, $settings)) {
            return $errors;
        }

        $voters = json_decode($params['voters']);
        if (!is_array($voters) && !is_numeric($voters[0])) {
            return $this->basicResponse(400, ['error' => 'Voters have to be an array of IDs!']);
        }
        Voter::destroy($voters);

        return new VoterListFull($voterlist);
    }

    public function sendInvites(Ballot $service, Request $request, VoterList $voterlist)
    {
        $params = $request->all();
        $settings = [
            // UUID of the ballot
            'batch' =>
            'required|uuid',

            // JSON Array of codes, should match in number to number of voters
            'codes' =>
            'required|array',

            // Email template, can have placeholders for:
            // %%CODE%% - the code that the user got
            // %%LINK%% - voting link
            // Link is probably not needed as a btn is added automatically.
            'template' =>
            'required|string',

            'subject' =>
            'required|string',

            // URL of the ballot, MUST have %%CODE%% so it can be replaced
            'url' =>
            'required|string',

        ];

        if ($errors = $this->findErrors($params, $settings)) {
            return $errors;
        }

        if ($voterlist->checkVoterListHasBlockedVoters()) {
            return $this->basicResponse(409, [
                'error' => 'VoterList contains blocked voters.'
            ]);
        }

        $codes    = $params['codes'];
        $url      = $params['url'];
        $batch    = $params['batch'];
        $template = $params['template'];
        $subject  = $params['subject'];

        try {
            $status = $service->sendInvites($voterlist, $codes, $url, $batch, $template, $subject);
        } catch (\Exception $e) {
            Log::alert('Error while sending invites', ['error' => $e->getMessage()]);
            return $this->basicResponse(500, ['error' => $e->getMessage()]);
        }

        return $this->basicResponse(200);
    }

    public function sendSessionInvites(Ballot $service, Request $request, VoterList $voterlist)
    {
        $params = $request->all();
        $settings = [
            // UUID of the ballot
            'batch' =>
            'required|uuid',

            // JSON Array of codes, should match in number to number of voters
            'codes' =>
            'required|array',

            // Email template, can have placeholders for:
            // %%CODE%% - the code that the user got
            // Link is probably not needed as a btn is added automatically.
            'template' =>
            'required|string',

            'subject' =>
            'required|string',

        ];

        if ($errors = $this->findErrors($params, $settings)) {
            return $errors;
        }

        if ($voterlist->checkVoterListHasBlockedVoters()) {
            return $this->basicResponse(409, [
                'error' => 'VoterList contains blocked voters.'
            ]);
        }

        $codes    = $params['codes'];
        $batch    = $params['batch'];
        $template = $params['template'];
        $subject  = $params['subject'];

        try {
            $status = $service->sendSessionInvites($voterlist, $codes, $batch, $template, $subject);
        } catch (\Exception $e) {
            Log::alert('Error while sending session invites', ['error' => $e->getMessage()]);
            return $this->basicResponse(500, ['error' => $e->getMessage()]);
        }

        return $this->basicResponse(200);
    }

    public function sendResults(Ballot $service, Request $request, VoterList $voterlist)
    {

        $params = $request->all();
        $settings = [
            // UUID of the ballot
            'batch' =>
            'required|uuid',

            'template' =>
            'required|string',

            'subject' =>
            'required|string',

            'csv' =>
            'required|string',

            'resultLink' =>
            'required|string',
        ];

        if ($errors = $this->findErrors($params, $settings)) {
            return $errors;
        }

        $batch      = $params['batch'];
        $template   = $params['template'];
        $subject    = $params['subject'];
        $csv        = $params['csv'];
        $resultLink = $params['resultLink'];

        try {
            $status = $service->sendResults($voterlist, $batch, $template, $subject, $csv, $resultLink);
        } catch (\Exception $e) {
            Log::alert('Error while sending results', ['error' => $e->getMessage()]);
            return $this->basicResponse(500, ['error' => $e->getMessage()]);
        }

        return $this->basicResponse(200);
    }

    public function startTest(Ballot $service, Request $request)
    {
        $params = $request->all();
        $settings = [
            // Array of emails to send the test email to
            'to' =>
            'required|json',
            'to.*' =>
            'email',

            // Email template, can have placeholders for:
            // %%CODE%% - the code that the user got
            // %%LINK%% - voting link
            // Link is probably not needed as a btn is added automatically.
            'template' =>
            'required|string',

            'subject' =>
            'required|string',

            // URL of the ballot, MUST have %%CODE%% so it can be replaced
            'url' =>
            'required|string',

        ];

        if ($errors = $this->findErrors($params, $settings)) {
            return $errors;
        }

        $to       = json_decode($params['to']);
        $url      = $params['url'];
        $template = $params['template'];
        $subject  = $params['subject'];

        try {
            $status = $service->sendInvitesTest($to, $url, $template, $subject);
        } catch (\Exception $e) {
            Log::alert('Error while sending test invites', ['error' => $e->getMessage()]);
            return $this->basicResponse(500, ['error' => $e->getMessage()]);
        }

        return $this->basicResponse(200);
    }
}
