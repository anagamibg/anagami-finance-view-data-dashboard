<?php

namespace App\Http\Controllers\Databackd\Data;

use App\Helper\Reply;
use App\Http\Controllers\AccountBaseController;
use App\Models\Databackd\FinApiToken;
use App\Services\Databackd\Data\FinApiAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FinApiTokenController extends AccountBaseController
{

    public function __construct()
    {
        parent::__construct();
        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-data-integrations.title');
            abort_403(in_array('client', user_roles()) || !in_array('admin', user_roles()));

            return $next($request);
        });
    }

    /**
     * Create a token. The plain token is returned once in the response (key "token").
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
        ], [], [
            'name' => __('databackd-data-integrations.tokens.name'),
        ]);

        if ($validator->fails()) {
            return Reply::formErrors($validator);
        }

        [$token, $plain] = FinApiToken::generate(trim($request->name), user()->id, company()->id);

        // audit trail (never the token itself)
        FinApiAudit::success('token_created', $request, ['token_id' => $token->id, 'company_id' => (int)$token->company_id, 'user_id' => user()->id, 'name' => $token->name]);

        return Reply::successWithData(__('databackd-data-integrations.messages.token_created'), [
            'token' => $plain,
            'token_name' => $token->name,
            'html' => $this->tableHtml(),
        ]);
    }

    /**
     * Revoke (soft): revoked_at = now(). The row stays for the audit trail.
     */
    public function destroy(Request $request, $id)
    {
        $token = FinApiToken::findOrFail($id);

        if ($token->revoked_at === null) {
            $token->revoked_at = now();
            $token->save();

            FinApiAudit::success('token_revoked', $request, ['token_id' => $token->id, 'company_id' => (int)$token->company_id, 'user_id' => user()->id]);
        }

        return Reply::successWithData(__('databackd-data-integrations.messages.token_revoked'), [
            'html' => $this->tableHtml(),
        ]);
    }

    protected function tableHtml(): string
    {
        return view('databackd.data.integrations.tokens-table', [
            'tokens' => FinApiToken::orderByDesc('id')->get(),
            'canManage' => true,
        ])->render();
    }

}
