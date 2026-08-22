package bd.ac.primeuniversity.studentportal.ui.admitcard

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.AdmitCard
import bd.ac.primeuniversity.studentportal.databinding.ItemAdmitCardBinding

/** One row per published admit card, with a download / blocked status line. */
class AdmitCardAdapter(
    private val onClick: (AdmitCard) -> Unit,
) : ListAdapter<AdmitCard, AdmitCardAdapter.Holder>(DIFF) {

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): Holder {
        val binding = ItemAdmitCardBinding.inflate(
            LayoutInflater.from(parent.context), parent, false
        )
        return Holder(binding)
    }

    override fun onBindViewHolder(holder: Holder, position: Int) =
        holder.bind(getItem(position))

    inner class Holder(private val binding: ItemAdmitCardBinding) :
        RecyclerView.ViewHolder(binding.root) {

        fun bind(card: AdmitCard) {
            val ctx = binding.root.context
            binding.examName.text = card.examName

            val meta = listOfNotNull(
                card.semester?.takeIf { it.isNotBlank() },
                card.batch?.takeIf { it.isNotBlank() }
                    ?.let { ctx.getString(R.string.badge_batch, it) },
            ).joinToString(" · ")
            binding.cardMeta.text = meta
            binding.cardMeta.visibility = if (meta.isBlank()) View.GONE else View.VISIBLE

            if (card.allowed) {
                binding.status.setText(R.string.admit_card_download)
                binding.status.setTextColor(ContextCompat.getColor(ctx, R.color.cat_exam))
            } else {
                binding.status.setText(R.string.admit_card_blocked)
                binding.status.setTextColor(ContextCompat.getColor(ctx, R.color.accent))
            }
            binding.root.setOnClickListener { onClick(card) }
        }
    }

    companion object {
        private val DIFF = object : DiffUtil.ItemCallback<AdmitCard>() {
            override fun areItemsTheSame(a: AdmitCard, b: AdmitCard) = a.id == b.id
            override fun areContentsTheSame(a: AdmitCard, b: AdmitCard) = a == b
        }
    }
}
