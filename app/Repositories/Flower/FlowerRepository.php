<?php

namespace App\Repositories\Flower;

use App\Repositories\BaseRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use App\Models\FlowerRate\FlowerRate;

/**
 * Class FlowerRepository.
 */
class FlowerRepository extends BaseRepository
{
    /**
     * FlowerRepository constructor.
     *
     * @param  FlowerRate  $model
     */
    public function __construct(FlowerRate $model)
    {
        $this->model = $model;
    }

    /**
     * @param int    $paged
     * @param string $orderBy
     * @param string $sort
     *
     * @return mixed
     */
    public function getActivePaginated($paged = 25, $orderBy = 'created_at', $sort = 'desc'): LengthAwarePaginator
    {
        return $this->model
            ->with('roles', 'permissions', 'providers')
            ->active()
            ->orderBy($orderBy, $sort)
            ->paginate($paged);
    }

    /**
     * @param int    $paged
     * @param string $orderBy
     * @param string $sort
     *
     * @return LengthAwarePaginator
     */
    public function getInactivePaginated($paged = 25, $orderBy = 'created_at', $sort = 'desc'): LengthAwarePaginator
    {
        return $this->model
            ->with('roles', 'permissions', 'providers')
            ->active(false)
            ->orderBy($orderBy, $sort)
            ->paginate($paged);
    }

    /**
     * @param int    $paged
     * @param string $orderBy
     * @param string $sort
     *
     * @return LengthAwarePaginator
     */
    public function getDeletedPaginated($paged = 25, $orderBy = 'created_at', $sort = 'desc'): LengthAwarePaginator
    {
        return $this->model
            ->with('roles', 'permissions', 'providers')
            ->onlyTrashed()
            ->orderBy($orderBy, $sort)
            ->paginate($paged);
    }

    /**
     * @param array $data
     *
     * @throws \Exception
     * @throws \Throwable
     * @return FlowerRate
     */
    public function create(array $data): FlowerRate
    {
        return DB::transaction(function () use ($data) {
            $flowerRate = $this->model::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'active' => isset($data['active']) && $data['active'] === '1',
                'confirmation_code' => md5(uniqid(mt_rand(), true)),
                'confirmed' => isset($data['confirmed']) && $data['confirmed'] === '1',
            ]);

            // See if adding any additional permissions
            if (! isset($data['permissions']) || ! count($data['permissions'])) {
                $data['permissions'] = [];
            }

            if ($flowerRate) {
                // FlowerRate must have at least one role
                if (! count($data['roles'])) {
                    throw new GeneralException(__('exceptions.backend.access.flower_rates.role_needed_create'));
                }

                // Add selected roles/permissions
                $flowerRate->syncRoles($data['roles']);
                $flowerRate->syncPermissions($data['permissions']);

                //Send confirmation email if requested and account approval is off
                if ($flowerRate->confirmed === false && isset($data['confirmation_email']) && ! config('access.flower_rates.requires_approval')) {
                    $flowerRate->notify(new FlowerRateNeedsConfirmation($flowerRate->confirmation_code));
                }

                event(new FlowerRateCreated($flowerRate));

                return $flowerRate;
            }

            throw new GeneralException(__('exceptions.backend.access.flower_rates.create_error'));
        });
    }

    /**
     * @param FlowerRate  $flowerRate
     * @param array $data
     *
     * @throws GeneralException
     * @throws \Exception
     * @throws \Throwable
     * @return FlowerRate
     */
    public function update(FlowerRate $flowerRate, array $data): FlowerRate
    {
        $this->checkFlowerRateByEmail($flowerRate, $data['email']);

        // See if adding any additional permissions
        if (! isset($data['permissions']) || ! count($data['permissions'])) {
            $data['permissions'] = [];
        }

        return DB::transaction(function () use ($flowerRate, $data) {
            if ($flowerRate->update([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
            ])) {
                // Add selected roles/permissions
                $flowerRate->syncRoles($data['roles']);
                $flowerRate->syncPermissions($data['permissions']);

                event(new FlowerRateUpdated($flowerRate));

                return $flowerRate;
            }

            throw new GeneralException(__('exceptions.backend.access.flower_rates.update_error'));
        });
    }

    /**
     * @param FlowerRate $flowerRate
     * @param      $input
     *
     * @throws GeneralException
     * @return FlowerRate
     */
    public function updatePassword(FlowerRate $flowerRate, $input): FlowerRate
    {
        if ($flowerRate->update(['password' => $input['password']])) {
            event(new FlowerRatePasswordChanged($flowerRate));

            return $flowerRate;
        }

        throw new GeneralException(__('exceptions.backend.access.flower_rates.update_password_error'));
    }

    /**
     * @param FlowerRate $flowerRate
     * @param      $status
     *
     * @throws GeneralException
     * @return FlowerRate
     */
    public function mark(FlowerRate $flowerRate, $status): FlowerRate
    {
        if ($status === 0 && auth()->id() === $flowerRate->id) {
            throw new GeneralException(__('exceptions.backend.access.flower_rates.cant_deactivate_self'));
        }

        $flowerRate->active = $status;

        switch ($status) {
            case 0:
                event(new FlowerRateDeactivated($flowerRate));
            break;
            case 1:
                event(new FlowerRateReactivated($flowerRate));
            break;
        }

        if ($flowerRate->save()) {
            return $flowerRate;
        }

        throw new GeneralException(__('exceptions.backend.access.flower_rates.mark_error'));
    }

    /**
     * @param FlowerRate $flowerRate
     *
     * @throws GeneralException
     * @return FlowerRate
     */
    public function confirm(FlowerRate $flowerRate): FlowerRate
    {
        if ($flowerRate->confirmed) {
            throw new GeneralException(__('exceptions.backend.access.flower_rates.already_confirmed'));
        }

        $flowerRate->confirmed = true;
        $confirmed = $flowerRate->save();

        if ($confirmed) {
            event(new FlowerRateConfirmed($flowerRate));

            // Let FlowerRate know their account was approved
            if (config('access.flower_rates.requires_approval')) {
                $flowerRate->notify(new FlowerRateAccountActive);
            }

            return $flowerRate;
        }

        throw new GeneralException(__('exceptions.backend.access.flower_rates.cant_confirm'));
    }

    /**
     * @param FlowerRate $flowerRate
     *
     * @throws GeneralException
     * @return FlowerRate
     */
    public function unconfirm(FlowerRate $flowerRate): FlowerRate
    {
        if (! $flowerRate->confirmed) {
            throw new GeneralException(__('exceptions.backend.access.flower_rates.not_confirmed'));
        }

        if ($flowerRate->id === 1) {
            // Cant un-confirm admin
            throw new GeneralException(__('exceptions.backend.access.flower_rates.cant_unconfirm_admin'));
        }

        if ($flowerRate->id === auth()->id()) {
            // Cant un-confirm self
            throw new GeneralException(__('exceptions.backend.access.flower_rates.cant_unconfirm_self'));
        }

        $flowerRate->confirmed = false;
        $unconfirmed = $flowerRate->save();

        if ($unconfirmed) {
            event(new FlowerRateUnconfirmed($flowerRate));

            return $flowerRate;
        }

        throw new GeneralException(__('exceptions.backend.access.flower_rates.cant_unconfirm'));
    }

    /**
     * @param FlowerRate $flowerRate
     *
     * @throws GeneralException
     * @throws \Exception
     * @throws \Throwable
     * @return FlowerRate
     */
    public function forceDelete(FlowerRate $flowerRate): FlowerRate
    {
        if ($flowerRate->deleted_at === null) {
            throw new GeneralException(__('exceptions.backend.access.flower_rates.delete_first'));
        }

        return DB::transaction(function () use ($flowerRate) {
            // Delete associated relationships
            $flowerRate->passwordHistories()->delete();
            $flowerRate->providers()->delete();

            if ($flowerRate->forceDelete()) {
                event(new FlowerRatePermanentlyDeleted($flowerRate));

                return $flowerRate;
            }

            throw new GeneralException(__('exceptions.backend.access.flower_rates.delete_error'));
        });
    }

    /**
     * @param FlowerRate $flowerRate
     *
     * @throws GeneralException
     * @return FlowerRate
     */
    public function restore(FlowerRate $flowerRate): FlowerRate
    {
        if ($flowerRate->deleted_at === null) {
            throw new GeneralException(__('exceptions.backend.access.flower_rates.cant_restore'));
        }

        if ($flowerRate->restore()) {
            event(new FlowerRateRestored($flowerRate));

            return $flowerRate;
        }

        throw new GeneralException(__('exceptions.backend.access.flower_rates.restore_error'));
    }

    /**
     * @param FlowerRate $flowerRate
     * @param      $email
     *
     * @throws GeneralException
     */
    protected function checkFlowerRateByEmail(FlowerRate $flowerRate, $email)
    {
        // Figure out if email is not the same and check to see if email exists
        if ($flowerRate->email !== $email && $this->model->where('email', '=', $email)->first()) {
            throw new GeneralException(trans('exceptions.backend.access.flower_rates.email_error'));
        }
    }
}
